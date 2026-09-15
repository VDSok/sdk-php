<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

use Psr\Http\Message\ResponseInterface;
use Vdsok\Sdk\RateLimitInfo;

/**
 * Ответ API со статусом >= 400.
 *
 * Тело всегда одной формы `{"error": {code, message, request_id, details}}`,
 * но SDK обязан переживать и нестандартные ответы (например, HTML от
 * прокси при 502): тогда `errorCode` = `unknown`, а решение принимается по
 * HTTP-статусу. `getMessage()` — человекочитаемое сообщение API.
 *
 * Поле называется `errorCode`, а не `code` как в остальных SDK VDSok:
 * в `\Exception` уже есть унаследованное `protected $code`, и PHP не
 * разрешает переобъявить его как readonly — это фатальная ошибка на этапе
 * загрузки класса, которую не ловит ни `php -l`, ни `catch`. Строковый код
 * API — в `errorCode`, HTTP-статус — в `status` и в `getCode()`.
 */
class ApiError extends VdsokException
{
    /** Код 409, при котором повтор безопасен: первый запрос ещё выполняется. */
    public const IDEMPOTENCY_IN_PROGRESS = 'idempotency_in_progress';

    /** @var list<int> Статусы, после которых повтор безопасен для GET и идемпотентных мутаций. */
    public const RETRYABLE_STATUSES = [429, 502, 503, 504];

    /**
     * @param array<string, mixed>|null $details
     * @param array<string, mixed>      $body    Декодированное тело ответа (или [] если не JSON)
     * @param array<string, string>     $headers Заголовки ответа, имена в нижнем регистре
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $requestId = null,
        public readonly ?array $details = null,
        public readonly ?RateLimitInfo $rateLimit = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly bool $sandbox = false,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Собирает ошибку из PSR-7 ответа. Ключ идемпотентности прокидывается,
     * чтобы вызывающий мог повторить тот же запрос тем же ключом и получить
     * реплей вместо второго списания.
     */
    public static function fromResponse(ResponseInterface $response, ?string $idempotencyKey = null): self
    {
        $status = $response->getStatusCode();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        $raw = (string) $response->getBody();
        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error']
            : [];

        $code = isset($error['code']) && is_string($error['code']) && $error['code'] !== ''
            ? $error['code']
            : 'unknown';
        $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
            ? $error['message']
            : self::fallbackMessage($status, $raw);
        $requestId = isset($error['request_id']) && is_string($error['request_id'])
            ? $error['request_id']
            : ($headers['x-request-id'] ?? null);
        $details = isset($error['details']) && is_array($error['details']) ? $error['details'] : null;

        return new self(
            status: $status,
            errorCode: $code,
            message: $message,
            requestId: $requestId,
            details: $details,
            rateLimit: RateLimitInfo::fromHeaders($headers),
            retryAfter: self::parseRetryAfter($headers['retry-after'] ?? null),
            idempotencyKey: $idempotencyKey,
            body: is_array($decoded) ? $decoded : [],
            headers: $headers,
            sandbox: ($headers['x-sandbox'] ?? '') === 'true',
        );
    }

    /**
     * Можно ли безопасно повторить запрос (при условии, что сам запрос
     * идемпотентен): лимиты, недоступность апстрима и «первый запрос с этим
     * ключом ещё выполняется».
     */
    public function isRetryable(): bool
    {
        if (in_array($this->status, self::RETRYABLE_STATUSES, true)) {
            return true;
        }

        return $this->status === 409 && $this->errorCode === self::IDEMPOTENCY_IN_PROGRESS;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isInsufficientFunds(): bool
    {
        return $this->status === 402 || $this->errorCode === 'insufficient_funds';
    }

    public function isAuthError(): bool
    {
        return $this->status === 401;
    }

    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    /** `validation_error` кладёт ошибки по полям в `details.fields`. */
    public function fieldErrors(): array
    {
        $fields = $this->details['fields'] ?? null;

        return is_array($fields) ? $fields : [];
    }

    /**
     * Секунды до повтора из `Retry-After`: число или HTTP-дата.
     * Отрицательные/невалидные значения превращаются в null, чтобы
     * ретраи не зависли на мусоре от прокси.
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d+$/', $value) === 1) {
            return max(0, (int) $value);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return max(0, $ts - time());
    }

    private static function fallbackMessage(int $status, string $raw): string
    {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');
        if (strlen($snippet) > 200) {
            $snippet = substr($snippet, 0, 200) . '…';
        }

        return $snippet === '' ? "HTTP {$status}" : "HTTP {$status}: {$snippet}";
    }
}
