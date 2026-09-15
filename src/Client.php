<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Vdsok\Sdk\Exception\ConfigurationError;
use Vdsok\Sdk\Http\Transport;
use Vdsok\Sdk\Resources\Account;
use Vdsok\Sdk\Resources\Balance;
use Vdsok\Sdk\Resources\Catalog;
use Vdsok\Sdk\Resources\Domains;
use Vdsok\Sdk\Resources\Invoices;
use Vdsok\Sdk\Resources\Keys;
use Vdsok\Sdk\Resources\Servers;
use Vdsok\Sdk\Resources\SshKeys;
use Vdsok\Sdk\Resources\Webhooks as WebhookSubscriptions;

/**
 * Точка входа в VDSok Client API v1.
 *
 *   $vdsok = new \Vdsok\Sdk\Client('vk_live_...');
 *   $balance = $vdsok->balance->get();
 *   foreach ($vdsok->servers->iterate() as $server) { ... }
 *
 * Опции конструктора:
 *   baseUrl        string   https://vdsok.guru/api/v1
 *   timeout        float    30 — секунд на запрос (применяется к Guzzle, который SDK создаёт сам)
 *   maxRetries     int      2 — повторов после первой попытки (GET и идемпотентные мутации)
 *   maxRetryDelay  float    60 — потолок паузы между попытками, даже если Retry-After больше
 *   httpClient     PSR-18   свой клиент (иначе Guzzle, иначе php-http/discovery)
 *   requestFactory PSR-17
 *   streamFactory  PSR-17
 *   appInfo        string   добавляется к User-Agent, например "my-panel/2.1"
 *   sleeper        callable(float): void — пауза ретраев (для тестов)
 *   idempotencyKeyFactory callable(): string
 *   requestIdFactory      callable(): string
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://vdsok.guru/api/v1';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_MAX_RETRY_DELAY = 60.0;

    public readonly Account $account;
    public readonly Balance $balance;
    public readonly Invoices $invoices;
    public readonly Catalog $catalog;
    public readonly Servers $servers;
    public readonly Domains $domains;
    public readonly SshKeys $sshKeys;
    public readonly Keys $keys;
    public readonly WebhookSubscriptions $webhooks;

    private readonly Transport $transport;
    private readonly string $keyHint;
    private readonly bool $testKey;

    /** @param array<string, mixed> $options */
    public function __construct(string $apiKey, array $options = [])
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new ConfigurationError('API key is empty. Create one in the cabinet at /my/api.');
        }
        if (preg_match('/\s/', $apiKey) === 1) {
            throw new ConfigurationError('API key contains whitespace.');
        }
        // Для отладки и логов храним только подсказку вида vk_live_…7Qx2 —
        // сам ключ никогда не должен попасть в var_dump или исключение.
        $this->keyHint = self::hint($apiKey);
        $this->testKey = str_starts_with($apiKey, 'vk_test_');

        $baseUrl = rtrim((string) ($options['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new ConfigurationError("baseUrl must start with http:// or https://, got {$baseUrl}");
        }
        $timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            throw new ConfigurationError('timeout must be > 0 seconds');
        }
        $maxRetries = (int) ($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);
        $maxRetryDelay = (float) ($options['maxRetryDelay'] ?? self::DEFAULT_MAX_RETRY_DELAY);

        [$http, $requestFactory, $streamFactory] = self::resolveHttp($options, $timeout);

        $userAgent = 'vdsok-sdk-php/' . self::VERSION;
        if (!empty($options['appInfo'])) {
            $userAgent .= ' ' . trim((string) $options['appInfo']);
        }

        $this->transport = new Transport(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            http: $http,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            userAgent: $userAgent,
            maxRetries: $maxRetries,
            maxRetryDelay: $maxRetryDelay,
            baseRetryDelay: (float) ($options['baseRetryDelay'] ?? 0.5),
            sleeper: self::closureOrNull($options['sleeper'] ?? null),
            idempotencyKeyFactory: self::closureOrNull($options['idempotencyKeyFactory'] ?? null),
            requestIdFactory: self::closureOrNull($options['requestIdFactory'] ?? null),
        );

        $this->account = new Account($this->transport);
        $this->balance = new Balance($this->transport);
        $this->invoices = new Invoices($this->transport);
        $this->catalog = new Catalog($this->transport);
        $this->servers = new Servers($this->transport);
        $this->domains = new Domains($this->transport);
        $this->sshKeys = new SshKeys($this->transport);
        $this->keys = new Keys($this->transport);
        $this->webhooks = new WebhookSubscriptions($this->transport);
    }

    /** `GET /me` — кто я: ключ, скоупы, режим, лимиты. Самый дешёвый способ проверить ключ. */
    public function me(): ApiResponse
    {
        return $this->transport->request('GET', '/me');
    }

    /** `GET /health` — жив ли API и не выключен ли он персоналом. */
    public function health(): ApiResponse
    {
        return $this->transport->request('GET', '/health');
    }

    /** `GET /openapi.json` — актуальная спецификация. */
    public function openapi(): ApiResponse
    {
        return $this->transport->request('GET', '/openapi.json');
    }

    /**
     * Произвольный вызов для эндпоинтов, которых SDK ещё не знает.
     *
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $options `idempotent`, `idempotency_key`, `headers`, `accept`
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): ApiResponse
    {
        return $this->transport->request($method, $path, $query, $body, $options);
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /** true для `vk_test_…` — все мутации будут симулированы (sandbox). */
    public function isTestKey(): bool
    {
        return $this->testKey;
    }

    /** Подсказка ключа вида `vk_live_…7Qx2` — безопасна для логов. */
    public function keyHint(): string
    {
        return $this->keyHint;
    }

    /** var_dump/print_r не должны раскрывать ключ. */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->keyHint,
            'baseUrl' => $this->transport->baseUrl(),
            'version' => self::VERSION,
        ];
    }

    public static function hint(string $apiKey): string
    {
        $prefix = 'vk_';
        if (preg_match('/^(vk_(?:live|test)_)/', $apiKey, $m) === 1) {
            $prefix = $m[1];
        }
        $tail = strlen($apiKey) > strlen($prefix) + 4 ? substr($apiKey, -4) : '';

        return $prefix . '…' . $tail;
    }

    /**
     * Подбор PSR-18 клиента и PSR-17 фабрик: явные из опций → Guzzle →
     * php-http/discovery. Guzzle создаётся с http_errors=false, иначе он
     * бросал бы свои исключения на 4xx раньше, чем SDK разберёт тело ошибки.
     *
     * @return array{0: ClientInterface, 1: RequestFactoryInterface, 2: StreamFactoryInterface}
     */
    private static function resolveHttp(array $options, float $timeout): array
    {
        $http = $options['httpClient'] ?? null;
        $requestFactory = $options['requestFactory'] ?? null;
        $streamFactory = $options['streamFactory'] ?? null;

        if ($http !== null && !$http instanceof ClientInterface) {
            throw new ConfigurationError('httpClient must implement Psr\Http\Client\ClientInterface');
        }
        if ($requestFactory !== null && !$requestFactory instanceof RequestFactoryInterface) {
            throw new ConfigurationError('requestFactory must implement Psr\Http\Message\RequestFactoryInterface');
        }
        if ($streamFactory !== null && !$streamFactory instanceof StreamFactoryInterface) {
            throw new ConfigurationError('streamFactory must implement Psr\Http\Message\StreamFactoryInterface');
        }

        if ($http === null && class_exists(\GuzzleHttp\Client::class)) {
            $http = new \GuzzleHttp\Client([
                'timeout' => $timeout,
                'connect_timeout' => min(10.0, $timeout),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }
        if (($requestFactory === null || $streamFactory === null) && class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $factory = new \GuzzleHttp\Psr7\HttpFactory();
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }
        if (($requestFactory === null || $streamFactory === null) && class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }
        if ($http === null && class_exists(\Http\Discovery\Psr18ClientDiscovery::class)) {
            try {
                $http = \Http\Discovery\Psr18ClientDiscovery::find();
            } catch (\Throwable) {
                $http = null;
            }
        }
        if (($requestFactory === null || $streamFactory === null) && class_exists(\Http\Discovery\Psr17FactoryDiscovery::class)) {
            try {
                $requestFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();
                $streamFactory ??= \Http\Discovery\Psr17FactoryDiscovery::findStreamFactory();
            } catch (\Throwable) {
                // ниже упадём с понятным сообщением
            }
        }

        if ($http === null) {
            throw new ConfigurationError(
                'No PSR-18 HTTP client found. Run `composer require guzzlehttp/guzzle` '
                . 'or pass one via the `httpClient` option.',
            );
        }
        if ($requestFactory === null || $streamFactory === null) {
            throw new ConfigurationError(
                'No PSR-17 factories found. Run `composer require guzzlehttp/guzzle` (or nyholm/psr7) '
                . 'or pass `requestFactory` and `streamFactory` options.',
            );
        }

        return [$http, $requestFactory, $streamFactory];
    }

    private static function closureOrNull(mixed $callable): ?\Closure
    {
        if ($callable === null) {
            return null;
        }
        if (!is_callable($callable)) {
            throw new ConfigurationError('sleeper / idempotencyKeyFactory / requestIdFactory must be callable');
        }

        return \Closure::fromCallable($callable);
    }
}
