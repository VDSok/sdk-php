<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Vdsok\Sdk\Exception\ApiError;
use Vdsok\Sdk\Exception\TransportError;

final class RetryTest extends TestCase
{
    public function testGetHonoursRetryAfterOn429(): void
    {
        $this->respondError(429, 'rate_limited', 'Too many requests', ['bucket' => 'normal'], ['Retry-After' => '3']);
        $this->respond(200, ['balance' => '1.00']);

        $res = $this->client()->balance->get();

        self::assertSame('1.00', $res['balance']);
        self::assertCount(2, $this->requests());
        self::assertSame([3.0], $this->sleeps);
    }

    public function testGetUsesExponentialBackoffWithoutRetryAfter(): void
    {
        $this->respondError(503, 'upstream_unavailable');
        $this->respondError(503, 'upstream_unavailable');
        $this->respond(200, []);

        $this->client()->me();

        self::assertCount(3, $this->requests());
        self::assertCount(2, $this->sleeps);
        // 0.5 * 2^0 и 0.5 * 2^1 плюс джиттер до +25 %
        self::assertGreaterThanOrEqual(0.5, $this->sleeps[0]);
        self::assertLessThanOrEqual(0.625, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(1.0, $this->sleeps[1]);
        self::assertLessThanOrEqual(1.25, $this->sleeps[1]);
    }

    public function testEachRetryableStatusIsRetriedForGet(): void
    {
        foreach ([429, 502, 503, 504] as $status) {
            $this->http->reset();
            $this->sleeps = [];
            $this->respondError($status, 'whatever');
            $this->respond(200, ['status' => $status]);

            $res = $this->client()->health();

            self::assertSame($status, $res['status']);
            self::assertCount(2, $this->requests(), "status {$status} should be retried");
        }
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $this->respondError(503, 'api_disabled', 'off', null, ['Retry-After' => '1']);
        $this->respondError(503, 'api_disabled', 'off', null, ['Retry-After' => '1']);
        $this->respondError(503, 'api_disabled', 'off', null, ['Retry-After' => '1']);
        $this->respond(200, []);

        try {
            $this->client(['maxRetries' => 2])->me();
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(503, $e->status);
            self::assertSame('api_disabled', $e->errorCode);
            self::assertSame(1, $e->retryAfter);
        }
        self::assertCount(3, $this->requests());
        self::assertSame([1.0, 1.0], $this->sleeps);
    }

    public function testZeroRetriesDisablesRetrying(): void
    {
        $this->respondError(429, 'rate_limited', 'slow down', null, ['Retry-After' => '5']);
        $this->respond(200, []);

        $this->expectException(ApiError::class);
        try {
            $this->client(['maxRetries' => 0])->me();
        } finally {
            self::assertCount(1, $this->requests());
            self::assertSame([], $this->sleeps);
        }
    }

    public function testNonIdempotentPostIsNeverRetried(): void
    {
        $this->respondError(503, 'upstream_unavailable', 'panel down', null, ['Retry-After' => '2']);
        $this->respond(202, ['status' => 'accepted']);

        try {
            $this->client()->servers->actions->restart(2001);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(503, $e->status);
            self::assertNull($e->idempotencyKey);
        }
        self::assertCount(1, $this->requests());
        self::assertSame([], $this->sleeps);
    }

    public function testIdempotentPostIsRetriedWithTheSameKey(): void
    {
        $this->respondError(502, 'upstream_error');
        $this->respond(201, ['server' => ['id' => 2002], 'root_password' => 'x']);

        $res = $this->client()->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04']);

        [$first, $second] = $this->requests();
        self::assertSame('POST', $first->getMethod());
        self::assertNotSame('', $first->getHeaderLine('Idempotency-Key'));
        self::assertSame($first->getHeaderLine('Idempotency-Key'), $second->getHeaderLine('Idempotency-Key'));
        self::assertSame($first->getHeaderLine('Idempotency-Key'), $res->idempotencyKey);
        self::assertNotSame($first->getHeaderLine('X-Request-ID'), $second->getHeaderLine('X-Request-ID'));
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(2002, $res->get('server.id'));
    }

    public function testIdempotentDeleteIsRetried(): void
    {
        $this->respondError(504, 'upstream_timeout');
        $this->respond(200, ['server_id' => 2001, 'status' => 'cancelled']);

        $res = $this->client()->servers->delete(2001);

        self::assertSame('cancelled', $res['status']);
        self::assertCount(2, $this->requests());
        self::assertSame('DELETE', $this->requests()[0]->getMethod());
    }

    public function testIdempotencyInProgressIsRetriedAfterRetryAfter(): void
    {
        $this->respondError(409, 'idempotency_in_progress', 'still running', null, ['Retry-After' => '2']);
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'paid']);

        $res = $this->client()->invoices->pay(10231);

        self::assertSame('paid', $res['status']);
        self::assertCount(2, $this->requests());
        self::assertSame([2.0], $this->sleeps);
    }

    public function testOtherConflictsAreNotRetried(): void
    {
        $this->respondError(409, 'idempotency_conflict', 'different body');
        $this->respond(200, []);

        try {
            $this->client()->invoices->pay(10231);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('idempotency_conflict', $e->errorCode);
            self::assertFalse($e->isRetryable());
        }
        self::assertCount(1, $this->requests());
    }

    public function testServerErrorAndPaymentRequiredAreNotRetried(): void
    {
        foreach ([[500, 'server_error'], [402, 'insufficient_funds'], [400, 'validation_error'], [404, 'not_found']] as [$status, $code]) {
            $this->http->reset();
            $this->sleeps = [];
            $this->respondError($status, $code);
            $this->respond(200, []);
            try {
                $this->client()->servers->renew(2001, ['months' => 1]);
                self::fail("expected ApiError for {$status}");
            } catch (ApiError $e) {
                self::assertSame($status, $e->status);
            }
            self::assertCount(1, $this->requests(), "{$status} must not be retried");
        }
    }

    public function testNetworkFailureIsRetriedForGet(): void
    {
        $this->http->addException($this->networkException());
        $this->respond(200, ['ok' => true]);

        $res = $this->client()->me();

        self::assertTrue($res['ok']);
        self::assertCount(2, $this->requests());
        self::assertCount(1, $this->sleeps);
    }

    public function testNetworkFailureIsRetriedForIdempotentPost(): void
    {
        $this->http->addException($this->networkException());
        $this->respond(201, ['invoice_id' => 1]);

        $res = $this->client()->balance->topup(['amount' => '25.00', 'gateway' => 'cryptobot']);

        self::assertSame(1, $res['invoice_id']);
        self::assertCount(2, $this->requests());
    }

    public function testNetworkFailureOnNonIdempotentPostBecomesTransportError(): void
    {
        $this->http->addException($this->networkException('connection reset'));
        $this->respond(200, []);

        try {
            $this->client()->servers->actions->stop(2001);
            self::fail('expected TransportError');
        } catch (TransportError $e) {
            self::assertSame('POST', $e->method);
            self::assertSame('https://vdsok.guru/api/v1/servers/2001/actions/power', $e->url);
            self::assertStringContainsString('connection reset', $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertNull($e->idempotencyKey);
        }
        self::assertCount(1, $this->requests());
    }

    public function testNetworkFailureAfterRetriesKeepsIdempotencyKey(): void
    {
        $this->http->addException($this->networkException());
        $this->http->addException($this->networkException());
        $this->http->addException($this->networkException());

        try {
            $this->client()->servers->create(['tariff_id' => 12, 'os' => 'debian-12'], 'my-order-key-000001');
            self::fail('expected TransportError');
        } catch (TransportError $e) {
            self::assertSame('my-order-key-000001', $e->idempotencyKey);
        }
        self::assertCount(3, $this->requests());
    }

    public function testRetryAfterIsCappedByMaxRetryDelay(): void
    {
        $this->respondError(429, 'rate_limited', 'x', null, ['Retry-After' => '3600']);
        $this->respond(200, []);

        $this->client(['maxRetryDelay' => 10])->me();

        self::assertSame([10.0], $this->sleeps);
    }

    public function testRetryAfterHttpDateIsParsed(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 30);
        $seconds = ApiError::parseRetryAfter($date);
        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(28, $seconds);
        self::assertLessThanOrEqual(30, $seconds);
        self::assertSame(0, ApiError::parseRetryAfter(gmdate('D, d M Y H:i:s \G\M\T', time() - 100)));
        self::assertSame(7, ApiError::parseRetryAfter(' 7 '));
        self::assertNull(ApiError::parseRetryAfter('soon'));
        self::assertNull(ApiError::parseRetryAfter(null));
        self::assertNull(ApiError::parseRetryAfter(''));
    }

    /**
     * Регрессия: `Retry-After` HTTP-датой из прошлого (рассинхрон часов с
     * edge-прокси) парсится в 0, и раньше SDK выпускал все попытки подряд
     * без паузы — прямо в тот же лимитер, который его и притормозил.
     */
    public function testStaleRetryAfterDateStillPausesAtLeastTheBaseDelay(): void
    {
        $stale = gmdate('D, d M Y H:i:s \G\M\T', time() - 120);
        $this->respondError(429, 'rate_limited', 'x', null, ['Retry-After' => $stale]);
        $this->respond(200, []);

        $this->client()->me();

        self::assertCount(2, $this->requests());
        self::assertSame([0.5], $this->sleeps);
    }

    public function testRetryAfterZeroSecondsStillPauses(): void
    {
        $this->respondError(503, 'upstream_unavailable', 'x', null, ['Retry-After' => '0']);
        $this->respond(200, []);

        $this->client()->me();

        self::assertSame([0.5], $this->sleeps);
    }

    /** Пауза из `Retry-After` не должна проседать ниже baseRetryDelay, но и не расти сверх неё. */
    public function testRetryAfterLongerThanBaseDelayIsUsedAsIs(): void
    {
        $this->respondError(429, 'rate_limited', 'x', null, ['Retry-After' => '2']);
        $this->respond(200, []);

        $this->client()->me();

        self::assertSame([2.0], $this->sleeps);
    }
}
