<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Vdsok\Sdk\Client;
use Vdsok\Sdk\Exception\ApiError;
use Vdsok\Sdk\Exception\ConfigurationError;
use Vdsok\Sdk\Util\Uuid;

final class IdempotencyTest extends TestCase
{
    /** Все операции спецификации с `x-idempotent: true`. */
    public static function idempotentOperations(): iterable
    {
        yield 'topup' => [static fn (Client $c, ?string $k) => $c->balance->topup(['amount' => '25.00', 'gateway' => 'cryptobot'], $k)];
        yield 'pay invoice' => [static fn (Client $c, ?string $k) => $c->invoices->pay(10231, $k)];
        yield 'create server' => [static fn (Client $c, ?string $k) => $c->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04'], $k)];
        yield 'delete server' => [static fn (Client $c, ?string $k) => $c->servers->delete(2001, $k)];
        yield 'renew server' => [static fn (Client $c, ?string $k) => $c->servers->renew(2001, ['months' => 3], $k)];
        yield 'add ip' => [static fn (Client $c, ?string $k) => $c->servers->ips->add(2001, $k)];
        yield 'register domain' => [static fn (Client $c, ?string $k) => $c->domains->register(['name' => 'example.com'], $k)];
        yield 'renew domain' => [static fn (Client $c, ?string $k) => $c->domains->renew(77, 1, $k)];
        yield 'transfer domain' => [static fn (Client $c, ?string $k) => $c->domains->transfer(['name' => 'example.org', 'auth_code' => 'abc'], $k)];
    }

    #[DataProvider('idempotentOperations')]
    public function testKeyIsGeneratedAndExposed(\Closure $call): void
    {
        $this->respond(200, ['ok' => true]);
        $res = $call($this->client(), null);

        $key = $this->lastRequest()->getHeaderLine('Idempotency-Key');
        self::assertTrue(Uuid::isV4($key), "generated key must be a UUID v4, got {$key}");
        self::assertSame($key, $res->idempotencyKey);
    }

    #[DataProvider('idempotentOperations')]
    public function testCallerKeyIsUsedVerbatim(\Closure $call): void
    {
        $this->respond(200, ['ok' => true]);
        $res = $call($this->client(), 'order-2026-09-15-00042');

        self::assertSame('order-2026-09-15-00042', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertSame('order-2026-09-15-00042', $res->idempotencyKey);
    }

    #[DataProvider('idempotentOperations')]
    public function testErrorCarriesTheKeyForASafeRetry(\Closure $call): void
    {
        $this->respondError(402, 'insufficient_funds', 'no money', ['shortfall' => '1.80']);
        try {
            $call($this->client(), null);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame($this->lastRequest()->getHeaderLine('Idempotency-Key'), $e->idempotencyKey);
            self::assertTrue(Uuid::isV4((string) $e->idempotencyKey));
        }
    }

    public static function nonIdempotentOperations(): iterable
    {
        yield 'update server' => [static fn (Client $c) => $c->servers->update(2001, ['auto_renew' => true])];
        yield 'power' => [static fn (Client $c) => $c->servers->actions->power(2001, 'start')];
        yield 'reinstall' => [static fn (Client $c) => $c->servers->actions->reinstall(2001, ['os' => 'debian-12'])];
        yield 'reset password' => [static fn (Client $c) => $c->servers->actions->resetPassword(2001)];
        yield 'delete ip' => [static fn (Client $c) => $c->servers->ips->delete(2001, 501)];
        yield 'ptr' => [static fn (Client $c) => $c->servers->ips->setPtr(2001, 501, 'mail.example.com')];
        yield 'payment link' => [static fn (Client $c) => $c->invoices->paymentLink(10231)];
        yield 'create ssh key' => [static fn (Client $c) => $c->sshKeys->create('laptop', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample')];
        yield 'update domain' => [static fn (Client $c) => $c->domains->update(77, ['privacy' => false])];
        yield 'nameservers' => [static fn (Client $c) => $c->domains->setNameservers(77, ['ns1.example.net', 'ns2.example.net'])];
        yield 'create webhook' => [static fn (Client $c) => $c->webhooks->create(['url' => 'https://hooks.example.com/x', 'events' => ['ping']])];
        yield 'revoke key' => [static fn (Client $c) => $c->keys->revoke(3)];
        yield 'get' => [static fn (Client $c) => $c->servers->get(2001)];
    }

    #[DataProvider('nonIdempotentOperations')]
    public function testNonIdempotentOperationsSendNoKey(\Closure $call): void
    {
        $this->respond(200, ['ok' => true]);
        $res = $call($this->client());

        self::assertFalse($this->lastRequest()->hasHeader('Idempotency-Key'));
        self::assertNull($res->idempotencyKey);
    }

    public function testTooShortKeyIsRejectedBeforeSending(): void
    {
        $client = $this->client();
        try {
            $client->servers->delete(2001, 'short');
            self::fail('expected ConfigurationError');
        } catch (ConfigurationError $e) {
            self::assertStringContainsString('16..128', $e->getMessage());
        }
        self::assertCount(0, $this->requests());
    }

    public function testTooLongKeyIsRejected(): void
    {
        $this->expectException(ConfigurationError::class);
        $this->client()->servers->delete(2001, str_repeat('k', 129));
    }

    public function testBoundaryKeyLengthsAreAccepted(): void
    {
        $this->respond(200, []);
        $this->respond(200, []);
        $client = $this->client();
        $client->servers->delete(2001, str_repeat('a', 16));
        $client->servers->delete(2001, str_repeat('b', 128));
        self::assertCount(2, $this->requests());
    }

    public function testCustomKeyFactoryIsUsed(): void
    {
        $this->respond(201, []);
        $n = 0;
        $client = $this->client(['idempotencyKeyFactory' => static function () use (&$n): string {
            $n++;

            return sprintf('custom-key-%016d', $n);
        }]);
        $client->servers->ips->add(2001);

        self::assertSame('custom-key-0000000000000001', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testReplayWithTheSameKeyIsPossibleAfterFailure(): void
    {
        $this->respondError(504, 'upstream_timeout');
        $this->respondError(504, 'upstream_timeout');
        $this->respondError(504, 'upstream_timeout');
        $this->respond(201, ['server' => ['id' => 2002], 'root_password' => '']);

        $client = $this->client();
        $key = null;
        try {
            $client->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04']);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            $key = $e->idempotencyKey;
        }
        self::assertNotNull($key);

        // Повтор тем же ключом — сервер отдаст сохранённый результат, а не второй сервер.
        $res = $client->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04'], $key);
        self::assertSame($key, $res->idempotencyKey);
        self::assertSame($key, $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        self::assertCount(4, $this->requests());
    }
}
