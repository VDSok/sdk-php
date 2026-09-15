<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Vdsok\Sdk\Client;

/**
 * База для тестов: клиент SDK поверх php-http/mock-client, без сети и
 * без реального сна. Ключ — короткий плейсхолдер, чтобы экспорт в
 * публичный репозиторий не принял его за настоящий.
 */
abstract class TestCase extends BaseTestCase
{
    public const API_KEY = 'vk_test_example';
    public const LIVE_KEY = 'vk_live_example';

    protected MockClient $http;

    /** @var list<float> Паузы, которые SDK попросил бы у usleep */
    protected array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockClient(new Psr17Factory());
        $this->sleeps = [];
    }

    /** @param array<string, mixed> $options */
    protected function client(array $options = [], string $apiKey = self::API_KEY): Client
    {
        $factory = new Psr17Factory();

        return new Client($apiKey, $options + [
            'httpClient' => $this->http,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ]);
    }

    /** Поставить в очередь мок-клиента один ответ. */
    protected function respond(int $status = 200, array|string|null $body = [], array $headers = []): void
    {
        $this->http->addResponse($this->response($status, $body, $headers));
    }

    protected function response(int $status = 200, array|string|null $body = [], array $headers = []): Response
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers += ['Content-Type' => 'application/json'];
        }
        $headers += ['X-Request-ID' => 'req_test'];

        return new Response($status, $headers, $body);
    }

    /** Ответ-ошибка в стандартном конверте API. */
    protected function error(int $status, string $code, string $message = 'error', ?array $details = null, array $headers = []): Response
    {
        $error = ['code' => $code, 'message' => $message, 'request_id' => 'req_err'];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return $this->response($status, ['error' => $error], $headers);
    }

    protected function respondError(int $status, string $code, string $message = 'error', ?array $details = null, array $headers = []): void
    {
        $this->http->addResponse($this->error($status, $code, $message, $details, $headers));
    }

    /** @return list<RequestInterface> */
    protected function requests(): array
    {
        return array_values($this->http->getRequests());
    }

    protected function lastRequest(): RequestInterface
    {
        $requests = $this->requests();
        self::assertNotEmpty($requests, 'No request was sent');

        return $requests[count($requests) - 1];
    }

    protected function decodeBody(RequestInterface $request): ?array
    {
        $raw = (string) $request->getBody();

        return $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function networkException(string $message = 'connection reset'): FakeNetworkException
    {
        return new FakeNetworkException((new Psr17Factory())->createRequest('GET', 'https://example.test/'), $message);
    }
}
