<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\BinaryResponse;
use Vdsok\Sdk\Exception\ApiError;
use Vdsok\Sdk\Exception\ConfigurationError;
use Vdsok\Sdk\Exception\InvalidResponseError;
use Vdsok\Sdk\Exception\TransportError;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Util\Uuid;

/**
 * Один HTTP-вызов к API: сборка PSR-7 запроса, ретраи, разбор ответа.
 *
 * Правила повтора (общие для всех SDK VDSok):
 *  - повторяются только GET/HEAD и мутации с `Idempotency-Key` — у них
 *    повтор гарантированно не создаст второй сервер и не спишет дважды;
 *  - поводы: 429, 502, 503, 504, `409 idempotency_in_progress`, сетевой сбой;
 *  - пауза — из `Retry-After`, иначе экспоненциальная с джиттером; и то и
 *    другое ограничено `maxRetryDelay`, чтобы процесс не завис на часы.
 *
 * Ключ API живёт только здесь и никогда не попадает в сообщения исключений.
 */
final class Transport
{
    /** Опции запроса, понимаемые `request()` / `page()` / `binary()`. */
    public const OPT_IDEMPOTENT = 'idempotent';
    public const OPT_IDEMPOTENCY_KEY = 'idempotency_key';
    public const OPT_HEADERS = 'headers';
    public const OPT_ACCEPT = 'accept';

    private const IDEMPOTENCY_KEY_MIN = 16;
    private const IDEMPOTENCY_KEY_MAX = 128;

    /**
     * Ключ хранится замыканием, а не свойством: print_r/var_export печатают
     * приватные свойства любых вложенных объектов, и ключ утёк бы в лог
     * при дампе клиента. У замыкания захваченные переменные не печатаются.
     *
     * @var \Closure(): string
     */
    private readonly \Closure $authorization;

    /**
     * @param \Closure(float): void   $sleeper               Пауза между попытками (подменяется в тестах)
     * @param \Closure(): string      $idempotencyKeyFactory Генератор `Idempotency-Key`
     * @param \Closure(): string      $requestIdFactory      Генератор `X-Request-ID`
     */
    public function __construct(
        string $apiKey,
        private readonly string $baseUrl,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $userAgent,
        private readonly int $maxRetries = 2,
        private readonly float $maxRetryDelay = 60.0,
        private readonly float $baseRetryDelay = 0.5,
        private ?\Closure $sleeper = null,
        private ?\Closure $idempotencyKeyFactory = null,
        private ?\Closure $requestIdFactory = null,
    ) {
        if ($maxRetries < 0) {
            throw new ConfigurationError('maxRetries must be >= 0');
        }
        $this->authorization = static fn (): string => 'Bearer ' . $apiKey;
        $this->sleeper ??= static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->idempotencyKeyFactory ??= static fn (): string => Uuid::v4();
        $this->requestIdFactory ??= static fn (): string => Uuid::v4();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** var_dump не должен печатать ни ключ, ни внутренности HTTP-клиента. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'userAgent' => $this->userAgent, 'maxRetries' => $this->maxRetries];
    }

    /** Пауза через тот же sleeper, что и ретраи — так `Orders::wait()` тестируется без реального сна. */
    public function sleep(float $seconds): void
    {
        ($this->sleeper)($seconds);
    }

    /**
     * JSON-запрос → ApiResponse.
     *
     * @param array<string, mixed>      $query   null-значения выбрасываются, bool → "true"/"false"
     * @param array<string, mixed>|null $body    null = без тела; [] = `{}`
     * @param array<string, mixed>      $options см. OPT_*
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): ApiResponse
    {
        [$response, $idempotencyKey, $clientRequestId] = $this->execute($method, $path, $query, $body, $options);

        return new ApiResponse(
            $this->decodeJson($response),
            $response->getStatusCode(),
            self::normalizeHeaders($response),
            $idempotencyKey,
            $clientRequestId,
        );
    }

    /** То же, но для списков `{data, next_cursor}`. */
    public function page(string $method, string $path, array $query = [], ?array $body = null, array $options = []): Page
    {
        [$response, $idempotencyKey, $clientRequestId] = $this->execute($method, $path, $query, $body, $options);

        return new Page(
            $this->decodeJson($response),
            $response->getStatusCode(),
            self::normalizeHeaders($response),
            $idempotencyKey,
            $clientRequestId,
        );
    }

    /** Не-JSON ответ (PDF). `Accept` берётся из опций, по умолчанию `application/pdf`. */
    public function binary(string $method, string $path, array $query = [], array $options = []): BinaryResponse
    {
        $options[self::OPT_ACCEPT] ??= 'application/pdf';
        [$response, , $clientRequestId] = $this->execute($method, $path, $query, null, $options);

        return new BinaryResponse(
            (string) $response->getBody(),
            $response->getStatusCode(),
            self::normalizeHeaders($response),
            $clientRequestId,
        );
    }

    /**
     * @return array{0: ResponseInterface, 1: ?string, 2: string}
     */
    private function execute(string $method, string $path, array $query, ?array $body, array $options): array
    {
        $method = strtoupper($method);
        $url = $this->buildUrl($path, $query);
        $idempotencyKey = $this->resolveIdempotencyKey($options);
        // Повторять безопасно только то, что сервер сам умеет дедуплицировать.
        $retrySafe = in_array($method, ['GET', 'HEAD'], true) || $idempotencyKey !== null;

        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = $body === [] ? '{}' : json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        $attempt = 0;
        while (true) {
            $clientRequestId = ($this->requestIdFactory)();
            $request = $this->buildRequest($method, $url, $encodedBody, $idempotencyKey, $clientRequestId, $options);

            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // Только сетевые сбои имеют смысл повторять; RequestException
                // (кривой запрос) повторится с тем же результатом.
                if ($e instanceof NetworkExceptionInterface && $retrySafe && $attempt < $this->maxRetries) {
                    $this->sleep($this->backoff($attempt));
                    $attempt++;
                    continue;
                }
                throw new TransportError(
                    sprintf('%s %s failed: %s', $method, $url, $e->getMessage()),
                    $method,
                    $url,
                    $idempotencyKey,
                    $e,
                );
            }

            $status = $response->getStatusCode();

            // Редиректы клиент не ходит (allow_redirects => false), а в спеке
            // v1 нет ни одной операции с 3xx. Молча считать их успехом нельзя:
            // тело пустое, и вызывающий получил бы ApiResponse со всеми полями
            // null вместо намёка, что baseUrl указан по http или без /api/v1.
            if ($status >= 300 && $status < 400) {
                throw new InvalidResponseError(
                    sprintf(
                        'API redirected (%d) to %s — check baseUrl',
                        $status,
                        $response->getHeaderLine('Location') ?: '(no Location header)',
                    ),
                    $status,
                    $response->getHeaderLine('X-Request-ID') ?: null,
                );
            }

            if ($status >= 400) {
                $error = ApiError::fromResponse($response, $idempotencyKey);
                if ($retrySafe && $attempt < $this->maxRetries && $error->isRetryable()) {
                    $this->sleep($this->retryDelay($error, $attempt));
                    $attempt++;
                    continue;
                }
                throw $error;
            }

            return [$response, $idempotencyKey, $clientRequestId];
        }
    }

    private function buildRequest(
        string $method,
        string $url,
        ?string $encodedBody,
        ?string $idempotencyKey,
        string $clientRequestId,
        array $options,
    ): RequestInterface {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', ($this->authorization)())
            ->withHeader('Accept', $options[self::OPT_ACCEPT] ?? 'application/json')
            ->withHeader('User-Agent', $this->userAgent)
            ->withHeader('X-Request-ID', $clientRequestId);

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }
        if ($encodedBody !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encodedBody));
        }
        foreach ($options[self::OPT_HEADERS] ?? [] as $name => $value) {
            $request = $request->withHeader((string) $name, (string) $value);
        }

        return $request;
    }

    /**
     * Ключ идемпотентности: явный из опций, иначе сгенерированный, если
     * операция помечена идемпотентной; для остальных — null.
     */
    private function resolveIdempotencyKey(array $options): ?string
    {
        $explicit = $options[self::OPT_IDEMPOTENCY_KEY] ?? null;
        if ($explicit !== null) {
            if (!is_string($explicit)) {
                throw new ConfigurationError('Idempotency-Key must be a string');
            }
            $len = strlen($explicit);
            if ($len < self::IDEMPOTENCY_KEY_MIN || $len > self::IDEMPOTENCY_KEY_MAX) {
                throw new ConfigurationError(sprintf(
                    'Idempotency-Key must be %d..%d characters, got %d',
                    self::IDEMPOTENCY_KEY_MIN,
                    self::IDEMPOTENCY_KEY_MAX,
                    $len,
                ));
            }

            return $explicit;
        }
        if (($options[self::OPT_IDEMPOTENT] ?? false) === true) {
            return ($this->idempotencyKeyFactory)();
        }

        return null;
    }

    private function retryDelay(ApiError $error, int $attempt): float
    {
        if ($error->retryAfter !== null) {
            // Нижняя граница обязательна: `Retry-After` в виде HTTP-даты из
            // прошлого (рассинхрон часов с edge-прокси) даёт 0, и без max() SDK
            // выпустил бы все попытки подряд за микросекунды прямо в лимитер.
            return min(max((float) $error->retryAfter, $this->baseRetryDelay), $this->maxRetryDelay);
        }

        return $this->backoff($attempt);
    }

    /** Экспонента с джиттером до +25 %, чтобы клиенты не били в API синхронно после сбоя. */
    private function backoff(int $attempt): float
    {
        $delay = $this->baseRetryDelay * (2 ** $attempt);
        $delay *= 1 + mt_rand(0, 250) / 1000;

        return min($delay, $this->maxRetryDelay);
    }

    private function buildUrl(string $path, array $query): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $qs = self::buildQuery($query);

        return $qs === '' ? $url : $url . '?' . $qs;
    }

    /**
     * RFC 3986-кодирование; null выбрасывается (параметр «не задан»),
     * bool → строка, потому что `http_build_query` превратил бы false в "0".
     */
    public static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value instanceof \DateTimeInterface) {
                $value = \Vdsok\Sdk\Timestamp::format($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /** @return array<string, mixed> */
    private function decodeJson(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '' || $response->getStatusCode() === 204) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidResponseError(
                'API returned a non-JSON body with status ' . $response->getStatusCode(),
                $response->getStatusCode(),
                $response->getHeaderLine('X-Request-ID') ?: null,
                substr($raw, 0, 200),
            );
        }
        if (!is_array($decoded)) {
            throw new InvalidResponseError(
                'API returned a JSON scalar instead of an object',
                $response->getStatusCode(),
                $response->getHeaderLine('X-Request-ID') ?: null,
                substr($raw, 0, 200),
            );
        }

        return $decoded;
    }

    /** @return array<string, string> */
    private static function normalizeHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }
}
