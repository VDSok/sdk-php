<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Vdsok\Sdk\Exception\ApiError;
use Vdsok\Sdk\Exception\InvalidResponseError;
use Vdsok\Sdk\Exception\VdsokException;

final class ErrorTest extends TestCase
{
    public function testStandardErrorEnvelopeIsMapped(): void
    {
        $this->respondError(403, 'insufficient_scope', 'This key lacks servers:delete', ['required' => ['servers:delete']], [
            'X-RateLimit-Limit' => '120',
            'X-RateLimit-Remaining' => '7',
            'X-Sandbox' => 'true',
        ]);

        try {
            $this->client()->servers->delete(2001);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertInstanceOf(VdsokException::class, $e);
            self::assertSame(403, $e->status);
            self::assertSame(403, $e->getCode());
            self::assertSame('insufficient_scope', $e->errorCode);
            self::assertSame('This key lacks servers:delete', $e->getMessage());
            self::assertSame('req_err', $e->requestId);
            self::assertSame(['required' => ['servers:delete']], $e->details);
            self::assertSame(120, $e->rateLimit?->limit);
            self::assertSame(7, $e->rateLimit?->remaining);
            self::assertTrue($e->sandbox);
            self::assertTrue($e->isForbidden());
            self::assertFalse($e->isRetryable());
            self::assertSame('insufficient_scope', $e->body['error']['code']);
            self::assertSame('req_test', $e->headers['x-request-id']);
        }
    }

    public function testInsufficientFundsCarriesMoneyDetails(): void
    {
        $this->respondError(402, 'insufficient_funds', 'Balance 4.10 USD is below the required 5.90 USD', [
            'required' => '5.90', 'balance' => '4.10', 'shortfall' => '1.80', 'currency' => 'USD',
        ]);

        try {
            $this->client()->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04']);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertTrue($e->isInsufficientFunds());
            self::assertSame('1.80', $e->details['shortfall']);
            self::assertSame('5.90', $e->details['required']);
        }
    }

    public function testValidationErrorExposesFieldErrors(): void
    {
        $this->respondError(400, 'validation_error', 'months must be one of 1, 3, 6, 12', ['fields' => ['months' => 'invalid_period']]);

        try {
            $this->client()->servers->renew(2001, ['months' => 5]);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(['months' => 'invalid_period'], $e->fieldErrors());
        }
    }

    public function testPredicates(): void
    {
        $cases = [
            [401, 'invalid_token', 'isAuthError'],
            [404, 'not_found', 'isNotFound'],
            [429, 'rate_limited', 'isRateLimited'],
        ];
        foreach ($cases as [$status, $code, $predicate]) {
            $this->http->reset();
            $this->respondError($status, $code);
            try {
                $this->client(['maxRetries' => 0])->me();
                self::fail("expected ApiError {$status}");
            } catch (ApiError $e) {
                self::assertTrue($e->{$predicate}(), "{$predicate} for {$status}");
            }
        }
    }

    public function testRetryAfterOn429IsExposed(): void
    {
        $this->respondError(429, 'rate_limited', 'Too many requests', ['bucket' => 'expensive', 'limit' => 20], ['Retry-After' => '12']);
        try {
            $this->client(['maxRetries' => 0])->catalog->quote(['tariff_id' => 12, 'months' => 1]);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(12, $e->retryAfter);
            self::assertSame('expensive', $e->details['bucket']);
            self::assertTrue($e->isRetryable());
        }
    }

    public function testNonJsonErrorBodyFallsBackToStatus(): void
    {
        $this->respond(502, '<html><body><h1>502 Bad Gateway</h1></body></html>', [
            'Content-Type' => 'text/html',
            'X-Request-ID' => 'req_from_header',
        ]);

        try {
            $this->client(['maxRetries' => 0])->me();
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(502, $e->status);
            self::assertSame('unknown', $e->errorCode);
            self::assertSame('HTTP 502: 502 Bad Gateway', $e->getMessage());
            self::assertSame('req_from_header', $e->requestId);
            self::assertNull($e->details);
            self::assertSame([], $e->body);
            self::assertTrue($e->isRetryable());
        }
    }

    public function testEmptyErrorBody(): void
    {
        $this->respond(500, null);
        try {
            $this->client()->me();
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('unknown', $e->errorCode);
            self::assertSame('HTTP 500', $e->getMessage());
        }
    }

    public function testUnknownCodeIsToleratedByStatus(): void
    {
        $this->respondError(409, 'some_future_code', 'new thing');
        try {
            $this->client()->servers->actions->start(2001);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(409, $e->status);
            self::assertSame('some_future_code', $e->errorCode);
            self::assertFalse($e->isRetryable());
        }
    }

    public function testSandboxForbiddenIsAPlainForbidden(): void
    {
        $this->respondError(403, 'sandbox_not_supported', 'Webhooks need a live key');
        try {
            $this->client()->webhooks->list();
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('sandbox_not_supported', $e->errorCode);
            self::assertTrue($e->isForbidden());
        }
    }

    /**
     * Регрессия: `ApiError` объявлял `public readonly string $code`, и PHP
     * при загрузке класса падал фаталом «Cannot redeclare non-readonly
     * property Exception::$code as readonly». Фатал не ловится ни `catch`,
     * ни `php -l` — любая ошибка API убивала процесс. Поэтому код API живёт
     * в `errorCode`, а `getCode()` отдаёт HTTP-статус.
     */
    public function testApiErrorCanBeInstantiatedAndKeepsHttpStatusInGetCode(): void
    {
        $e = new ApiError(status: 404, errorCode: 'not_found', message: 'Server 1 not found');

        self::assertSame('not_found', $e->errorCode);
        self::assertSame(404, $e->status);
        self::assertSame(404, $e->getCode());
        self::assertTrue($e->isNotFound());
        self::assertFalse(property_exists($e, 'errorCodeMissing'));
    }

    /**
     * Редирект — не успех. Клиент настроен без следования редиректам, и в
     * спеке v1 нет ни одной операции с 3xx: 301 обычно означает, что baseUrl
     * задан по http или без `/api/v1`. Раньше вызывающий молча получал
     * ApiResponse с пустым телом.
     */
    public function testRedirectIsReportedInsteadOfBeingTreatedAsSuccess(): void
    {
        $this->respond(301, '', ['Location' => 'https://vdsok.guru/api/v1/balance']);

        try {
            $this->client(['baseUrl' => 'http://vdsok.guru/api/v1'])->balance->get();
            self::fail('expected InvalidResponseError');
        } catch (InvalidResponseError $e) {
            self::assertSame(301, $e->status);
            self::assertStringContainsString('redirected', $e->getMessage());
            self::assertStringContainsString('https://vdsok.guru/api/v1/balance', $e->getMessage());
            self::assertStringContainsString('baseUrl', $e->getMessage());
        }
        self::assertCount(1, $this->requests(), 'a redirect must not be retried');
    }

    public function testRedirectWithoutLocationStillFails(): void
    {
        $this->respond(302, '');

        $this->expectException(InvalidResponseError::class);
        $this->client()->me();
    }

    public function testErrorMessageNeverContainsTheApiKey(): void
    {
        $this->respondError(401, 'invalid_token', 'Unknown or revoked API key');
        try {
            $this->client([], 'vk_live_secretXYZ1')->me();
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertStringNotContainsString('vk_live_secretXYZ1', $e->getMessage());
            self::assertStringNotContainsString('vk_live_secretXYZ1', print_r($e->headers, true));
        }
    }
}
