<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Client;
use Vdsok\Sdk\Exception\ConfigurationError;
use Vdsok\Sdk\Exception\InvalidResponseError;
use Vdsok\Sdk\Http\Transport;
use Vdsok\Sdk\Util\Uuid;

final class ClientTest extends TestCase
{
    public function testSendsStandardHeaders(): void
    {
        $this->respond(200, ['account_id' => 57]);
        $this->client()->me();

        $req = $this->lastRequest();
        self::assertSame('GET', $req->getMethod());
        self::assertSame('https://vdsok.guru/api/v1/me', (string) $req->getUri());
        self::assertSame('Bearer ' . self::API_KEY, $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
        self::assertSame('vdsok-sdk-php/' . Client::VERSION, $req->getHeaderLine('User-Agent'));
        self::assertTrue(Uuid::isV4($req->getHeaderLine('X-Request-ID')));
        self::assertFalse($req->hasHeader('Idempotency-Key'));
        self::assertFalse($req->hasHeader('Content-Type'));
    }

    public function testRequestIdIsUniquePerRequest(): void
    {
        $this->respond(200, []);
        $this->respond(200, []);
        $client = $this->client();
        $client->health();
        $client->health();

        [$a, $b] = $this->requests();
        self::assertNotSame($a->getHeaderLine('X-Request-ID'), $b->getHeaderLine('X-Request-ID'));
    }

    public function testCustomBaseUrlTrailingSlashAndAppInfo(): void
    {
        $this->respond(200, []);
        $this->client(['baseUrl' => 'https://api.example.test/v1/', 'appInfo' => 'my-panel/2.1'])->account->get();

        $req = $this->lastRequest();
        self::assertSame('https://api.example.test/v1/account', (string) $req->getUri());
        self::assertSame('vdsok-sdk-php/' . Client::VERSION . ' my-panel/2.1', $req->getHeaderLine('User-Agent'));
    }

    public function testDefaultsAreAsDocumented(): void
    {
        self::assertSame('https://vdsok.guru/api/v1', Client::DEFAULT_BASE_URL);
        self::assertSame(30.0, Client::DEFAULT_TIMEOUT);
        self::assertSame(2, Client::DEFAULT_MAX_RETRIES);
        self::assertSame('1.0.0', Client::VERSION);
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->client([], '   ');
    }

    public function testInvalidBaseUrlIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->client(['baseUrl' => 'vdsok.guru/api/v1']);
    }

    public function testInvalidTimeoutIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->client(['timeout' => 0]);
    }

    public function testDumpsNeverExposeTheKey(): void
    {
        $client = $this->client([], 'vk_live_exampleABCD');

        // print_r и var_export игнорируют __debugInfo и печатают приватные
        // свойства вложенных объектов — ключ не должен храниться свойством.
        self::assertStringNotContainsString('vk_live_exampleABCD', print_r($client, true));
        self::assertStringNotContainsString('vk_live_exampleABCD', (string) json_encode($client));

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString('vk_live_exampleABCD', $dump);
        self::assertStringContainsString('vk_live_…ABCD', $dump);

        self::assertSame('vk_live_…ABCD', $client->keyHint());
        self::assertFalse($client->isTestKey());
        self::assertTrue($this->client()->isTestKey());
    }

    public function testResponseExposesRequestIdRateLimitAndSandbox(): void
    {
        $this->respond(200, ['balance' => '42.15', 'currency' => 'USD'], [
            'X-Request-ID' => 'req_8f1c2a9d4b',
            'X-RateLimit-Limit' => '120',
            'X-RateLimit-Remaining' => '119',
            'X-RateLimit-Reset' => '1760000000',
            'X-Sandbox' => 'true',
        ]);
        $res = $this->client()->balance->get();

        self::assertInstanceOf(ApiResponse::class, $res);
        self::assertSame(200, $res->statusCode);
        self::assertSame('req_8f1c2a9d4b', $res->requestId);
        self::assertTrue(Uuid::isV4((string) $res->clientRequestId));
        self::assertNotNull($res->rateLimit);
        self::assertSame(120, $res->rateLimit->limit);
        self::assertSame(119, $res->rateLimit->remaining);
        self::assertSame(1760000000, $res->rateLimit->reset);
        self::assertTrue($res->sandbox);
        self::assertSame('42.15', $res['balance']);
        self::assertSame('42.15', $res->get('balance'));
        self::assertSame(['balance' => '42.15', 'currency' => 'USD'], $res->toArray());
        self::assertSame('{"balance":"42.15","currency":"USD"}', json_encode($res));
    }

    public function testLiveKeyResponseIsNotSandbox(): void
    {
        $this->respond(200, []);
        $res = $this->client([], self::LIVE_KEY)->me();
        self::assertFalse($res->sandbox);
        self::assertNull($res->rateLimit);
    }

    public function testMoneyStaysAString(): void
    {
        $this->respond(200, ['price_hourly' => '0.0083', 'price_monthly' => '5.90']);
        $res = $this->client()->catalog->tariff(12);
        self::assertSame('0.0083', $res['price_hourly']);
        self::assertSame('5.90', $res['price_monthly']);
    }

    public function testDotPathAndDateTimeHelpers(): void
    {
        $this->respond(200, [
            'billing' => ['next_due_at' => '2026-10-01T00:00:00Z', 'auto_renew' => true],
            'cancelled_at' => null,
        ]);
        $res = $this->client()->servers->get(2001);

        self::assertTrue($res->get('billing.auto_renew'));
        self::assertNull($res->get('billing.missing'));
        self::assertSame('x', $res->get('billing.missing', 'x'));
        self::assertTrue($res->has('billing.next_due_at'));
        self::assertFalse($res->has('nope'));
        $due = $res->dateTime('billing.next_due_at');
        self::assertInstanceOf(\DateTimeImmutable::class, $due);
        self::assertSame('2026-10-01T00:00:00+00:00', $due->format(DATE_ATOM));
        self::assertNull($res->dateTime('cancelled_at'));
        self::assertNull($res['absent']);
    }

    public function testResponseIsImmutable(): void
    {
        $this->respond(200, ['a' => 1]);
        $res = $this->client()->me();
        $this->expectException(\LogicException::class);
        $res['a'] = 2;
    }

    public function testQueryDropsNullsAndFormatsBoolsAndDates(): void
    {
        $this->respond(200, ['data' => [], 'next_cursor' => null]);
        $this->client()->balance->transactions([
            'direction' => 'debit',
            'cursor' => null,
            'limit' => 10,
            'since' => new \DateTimeImmutable('2026-09-01 12:00:00', new \DateTimeZone('+03:00')),
            'flag' => false,
        ]);

        self::assertSame(
            'direction=debit&limit=10&since=2026-09-01T09%3A00%3A00Z&flag=false',
            $this->lastRequest()->getUri()->getQuery(),
        );
    }

    public function testBuildQueryEncodesRfc3986(): void
    {
        self::assertSame('name=%D0%BF%D1%80%D0%B8%D0%BC%D0%B5%D1%80.%D1%80%D1%84', Transport::buildQuery(['name' => 'пример.рф']));
        self::assertSame('a=1&a=2', Transport::buildQuery(['a' => [1, 2]]));
        self::assertSame('', Transport::buildQuery(['a' => null]));
    }

    public function testJsonBodyIsEncodedAndContentTypeSet(): void
    {
        $this->respond(200, []);
        $this->client()->servers->update(2001, ['notes' => 'prod, do not stop / ü', 'auto_renew' => false]);

        $req = $this->lastRequest();
        self::assertSame('PATCH', $req->getMethod());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertSame('{"notes":"prod, do not stop / ü","auto_renew":false}', (string) $req->getBody());
    }

    public function testEmptyBodyArrayBecomesJsonObject(): void
    {
        $this->respond(200, []);
        $this->client()->request('POST', '/whatever', [], []);
        self::assertSame('{}', (string) $this->lastRequest()->getBody());
    }

    public function testNoContentResponseGivesEmptyData(): void
    {
        $this->respond(204, null);
        $res = $this->client()->sshKeys->delete(3);
        self::assertSame(204, $res->statusCode);
        self::assertSame([], $res->toArray());
        self::assertCount(0, $res);
    }

    public function testNonJsonSuccessBodyIsAnInvalidResponse(): void
    {
        $this->respond(200, '<html>captive portal</html>', ['Content-Type' => 'text/html']);
        try {
            $this->client()->me();
            self::fail('expected InvalidResponseError');
        } catch (InvalidResponseError $e) {
            self::assertSame(200, $e->status);
            self::assertSame('req_test', $e->requestId);
            self::assertStringContainsString('captive portal', $e->bodyExcerpt);
        }
    }

    public function testAcceptedFlagFor202(): void
    {
        $this->respond(202, ['invoice_id' => 10231, 'status' => 'provisioning']);
        $res = $this->client()->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04']);
        self::assertTrue($res->isAccepted());
        self::assertSame('provisioning', $res['status']);
    }

    public function testEscapeHatchRequestPassesEverythingThrough(): void
    {
        $this->respond(200, ['ok' => true]);
        $res = $this->client()->request('PUT', 'future/endpoint', ['x' => 1], ['y' => 2], [
            'headers' => ['X-Custom' => 'yes'],
            'idempotency_key' => 'order-2026-09-15-0001',
        ]);

        $req = $this->lastRequest();
        self::assertSame('https://vdsok.guru/api/v1/future/endpoint?x=1', (string) $req->getUri());
        self::assertSame('yes', $req->getHeaderLine('X-Custom'));
        self::assertSame('order-2026-09-15-0001', $req->getHeaderLine('Idempotency-Key'));
        self::assertSame('{"y":2}', (string) $req->getBody());
        self::assertTrue($res['ok']);
        self::assertSame('order-2026-09-15-0001', $res->idempotencyKey);
    }

    public function testPdfIsReturnedAsBinary(): void
    {
        $this->respond(200, '%PDF-1.7 fake', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-10231.pdf"',
            'X-RateLimit-Limit' => '20',
        ]);
        $pdf = $this->client()->invoices->pdf(10231);

        $req = $this->lastRequest();
        self::assertSame('https://vdsok.guru/api/v1/invoices/10231/pdf', (string) $req->getUri());
        self::assertSame('application/pdf', $req->getHeaderLine('Accept'));
        self::assertSame('%PDF-1.7 fake', $pdf->body);
        self::assertSame(13, $pdf->size());
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame('invoice-10231.pdf', $pdf->filename());
        self::assertSame('req_test', $pdf->requestId);
        self::assertSame(20, $pdf->rateLimit?->limit);
    }

    public function testInvalidIdsAreRejectedBeforeSending(): void
    {
        $client = $this->client();
        $this->expectException(\InvalidArgumentException::class);
        $client->servers->get(0);
    }

    public function testStringIdsAreAccepted(): void
    {
        $this->respond(200, []);
        $this->client()->servers->get('2001');
        self::assertSame('/api/v1/servers/2001', $this->lastRequest()->getUri()->getPath());
        self::assertCount(0, $this->sleeps);
    }
}
