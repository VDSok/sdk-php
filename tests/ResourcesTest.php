<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Vdsok\Sdk\Client;
use Vdsok\Sdk\Util\Uuid;

/**
 * Матрица «метод SDK → HTTP-метод, путь, query, тело, Idempotency-Key»
 * для каждой операции спецификации vdsok-client-api-v1.yaml.
 */
final class ResourcesTest extends TestCase
{
    private const SSH_PUBKEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleExampleExampleExampleExampl user@laptop';

    /**
     * @return iterable<string, array{0: \Closure, 1: string, 2: string, 3: string, 4: ?array, 5: bool}>
     *   [вызов, метод, путь, query-строка, тело или null, ожидается ли Idempotency-Key]
     */
    public static function operations(): iterable
    {
        // Meta
        yield 'get_health' => [static fn (Client $c) => $c->health(), 'GET', '/health', '', null, false];
        yield 'get_openapi' => [static fn (Client $c) => $c->openapi(), 'GET', '/openapi.json', '', null, false];
        yield 'get_me' => [static fn (Client $c) => $c->me(), 'GET', '/me', '', null, false];

        // Account
        yield 'get_account' => [static fn (Client $c) => $c->account->get(), 'GET', '/account', '', null, false];
        yield 'get_balance' => [static fn (Client $c) => $c->balance->get(), 'GET', '/balance', '', null, false];
        yield 'list_transactions' => [
            static fn (Client $c) => $c->balance->transactions(['direction' => 'debit', 'since' => '2026-09-01T00:00:00Z', 'limit' => 10]),
            'GET', '/transactions', 'direction=debit&since=2026-09-01T00%3A00%3A00Z&limit=10', null, false,
        ];
        yield 'get_topup_info' => [static fn (Client $c) => $c->balance->topupInfo(), 'GET', '/balance/topup-info', '', null, false];
        yield 'create_topup' => [
            static fn (Client $c) => $c->balance->topup(['amount' => '25.00', 'gateway' => 'cryptobot']),
            'POST', '/balance/topup', '', ['amount' => '25.00', 'gateway' => 'cryptobot'], true,
        ];

        // Invoices
        yield 'list_invoices' => [
            static fn (Client $c) => $c->invoices->list(['status' => 'not_paid', 'type' => 'vds_renewal']),
            'GET', '/invoices', 'status=not_paid&type=vds_renewal', null, false,
        ];
        yield 'get_invoice' => [static fn (Client $c) => $c->invoices->get(10231), 'GET', '/invoices/10231', '', null, false];
        yield 'pay_invoice' => [static fn (Client $c) => $c->invoices->pay(10231), 'POST', '/invoices/10231/pay', '', null, true];
        yield 'create_invoice_payment_link' => [
            static fn (Client $c) => $c->invoices->paymentLink(10231, ['gateway' => 'cryptobot']),
            'POST', '/invoices/10231/payment-link', '', ['gateway' => 'cryptobot'], false,
        ];
        yield 'create_invoice_payment_link (no body)' => [
            static fn (Client $c) => $c->invoices->paymentLink(10231),
            'POST', '/invoices/10231/payment-link', '', null, false,
        ];

        // Catalog
        yield 'list_tariffs' => [static fn (Client $c) => $c->catalog->tariffs(), 'GET', '/catalog/tariffs', '', null, false];
        yield 'list_tariffs (location)' => [static fn (Client $c) => $c->catalog->tariffs(1), 'GET', '/catalog/tariffs', 'location_id=1', null, false];
        yield 'get_tariff' => [static fn (Client $c) => $c->catalog->tariff(12), 'GET', '/catalog/tariffs/12', '', null, false];
        yield 'list_os_images' => [static fn (Client $c) => $c->catalog->osImages(), 'GET', '/catalog/os', '', null, false];
        yield 'list_os_images (tariff)' => [static fn (Client $c) => $c->catalog->osImages(12), 'GET', '/catalog/os', 'tariff_id=12', null, false];
        yield 'list_locations' => [static fn (Client $c) => $c->catalog->locations(), 'GET', '/catalog/locations', '', null, false];
        yield 'list_zones' => [static fn (Client $c) => $c->catalog->zones(), 'GET', '/catalog/zones', '', null, false];
        yield 'get_quote (months)' => [
            static fn (Client $c) => $c->catalog->quote(['tariff_id' => 12, 'months' => 3, 'promo_code' => 'SAVE10']),
            'GET', '/catalog/quote', 'tariff_id=12&months=3&promo_code=SAVE10', null, false,
        ];
        yield 'get_quote (hours)' => [
            static fn (Client $c) => $c->catalog->quote(['tariff_id' => 12, 'hours' => 24, 'billing_cycle' => 'hourly']),
            'GET', '/catalog/quote', 'tariff_id=12&hours=24&billing_cycle=hourly', null, false,
        ];

        // Servers
        yield 'list_servers' => [
            static fn (Client $c) => $c->servers->list(['status' => 'active', 'ip' => '203.0.113.10', 'limit' => 50]),
            'GET', '/servers', 'status=active&ip=203.0.113.10&limit=50', null, false,
        ];
        yield 'create_server' => [
            static fn (Client $c) => $c->servers->create(['tariff_id' => 12, 'os' => 'ubuntu-24.04', 'name' => 'web-01', 'months' => 1, 'ssh_key_ids' => [3], 'custom_fields' => ['purpose' => 'web']]),
            'POST', '/servers', '', ['tariff_id' => 12, 'os' => 'ubuntu-24.04', 'name' => 'web-01', 'months' => 1, 'ssh_key_ids' => [3], 'custom_fields' => ['purpose' => 'web']], true,
        ];
        yield 'get_server' => [static fn (Client $c) => $c->servers->get(2001), 'GET', '/servers/2001', '', null, false];
        yield 'get_server (live)' => [static fn (Client $c) => $c->servers->get(2001, true), 'GET', '/servers/2001', 'include=live', null, false];
        yield 'update_server' => [
            static fn (Client $c) => $c->servers->update(2001, ['auto_renew' => true, 'notes' => 'prod, do not stop']),
            'PATCH', '/servers/2001', '', ['auto_renew' => true, 'notes' => 'prod, do not stop'], false,
        ];
        yield 'update_server (setAutoRenew)' => [
            static fn (Client $c) => $c->servers->setAutoRenew(2001, false),
            'PATCH', '/servers/2001', '', ['auto_renew' => false], false,
        ];
        yield 'delete_server' => [static fn (Client $c) => $c->servers->delete(2001), 'DELETE', '/servers/2001', '', null, true];
        yield 'get_server_status' => [static fn (Client $c) => $c->servers->status(2001), 'GET', '/servers/2001/status', '', null, false];
        yield 'get_server_refund_quote' => [static fn (Client $c) => $c->servers->refundQuote(2001), 'GET', '/servers/2001/refund-quote', '', null, false];
        yield 'renew_server (months)' => [
            static fn (Client $c) => $c->servers->renew(2001, ['months' => 3]),
            'POST', '/servers/2001/renew', '', ['months' => 3], true,
        ];
        yield 'renew_server (hours)' => [
            static fn (Client $c) => $c->servers->renew(2001, ['hours' => 48]),
            'POST', '/servers/2001/renew', '', ['hours' => 48], true,
        ];

        // Server actions
        yield 'power_server' => [
            static fn (Client $c) => $c->servers->actions->power(2001, 'restart'),
            'POST', '/servers/2001/actions/power', '', ['action' => 'restart'], false,
        ];
        yield 'power_server (start)' => [static fn (Client $c) => $c->servers->actions->start(2001), 'POST', '/servers/2001/actions/power', '', ['action' => 'start'], false];
        yield 'power_server (stop)' => [static fn (Client $c) => $c->servers->actions->stop(2001), 'POST', '/servers/2001/actions/power', '', ['action' => 'stop'], false];
        yield 'reinstall_server' => [
            static fn (Client $c) => $c->servers->actions->reinstall(2001, ['os' => 'debian-12', 'ssh_key_ids' => [3]]),
            'POST', '/servers/2001/actions/reinstall', '', ['os' => 'debian-12', 'ssh_key_ids' => [3]], false,
        ];
        yield 'reset_server_password' => [
            static fn (Client $c) => $c->servers->actions->resetPassword(2001),
            'POST', '/servers/2001/actions/reset-password', '', null, false,
        ];

        // Orders
        yield 'list_orders' => [
            static fn (Client $c) => $c->servers->orders->list(['status' => 'provisioning']),
            'GET', '/orders', 'status=provisioning', null, false,
        ];
        yield 'get_order' => [static fn (Client $c) => $c->servers->orders->get(10231), 'GET', '/orders/10231', '', null, false];

        // IPs
        yield 'list_server_ips' => [static fn (Client $c) => $c->servers->ips->list(2001), 'GET', '/servers/2001/ips', '', null, false];
        yield 'add_server_ip' => [static fn (Client $c) => $c->servers->ips->add(2001), 'POST', '/servers/2001/ips', '', null, true];
        yield 'get_server_ip_quote' => [static fn (Client $c) => $c->servers->ips->quote(2001), 'GET', '/servers/2001/ips/quote', '', null, false];
        yield 'delete_server_ip' => [static fn (Client $c) => $c->servers->ips->delete(2001, 501), 'DELETE', '/servers/2001/ips/501', '', null, false];
        yield 'set_server_ip_ptr' => [
            static fn (Client $c) => $c->servers->ips->setPtr(2001, 501, 'mail.example.com'),
            // Поле тела — `domain`: `ptr` ручка отвергает 400.
            'PUT', '/servers/2001/ips/501/ptr', '', ['domain' => 'mail.example.com'], false,
        ];
        yield 'clear_server_ip_ptr' => [
            static fn (Client $c) => $c->servers->ips->setPtr(2001, 501, null),
            'PUT', '/servers/2001/ips/501/ptr', '', ['domain' => ''], false,
        ];

        // SSH keys
        yield 'list_ssh_keys' => [static fn (Client $c) => $c->sshKeys->list(), 'GET', '/ssh-keys', '', null, false];
        yield 'create_ssh_key' => [
            static fn (Client $c) => $c->sshKeys->create('laptop', self::SSH_PUBKEY),
            'POST', '/ssh-keys', '', ['name' => 'laptop', 'public_key' => self::SSH_PUBKEY], false,
        ];
        yield 'delete_ssh_key' => [static fn (Client $c) => $c->sshKeys->delete(3), 'DELETE', '/ssh-keys/3', '', null, false];

        // Domains
        yield 'check_domain_availability' => [
            static fn (Client $c) => $c->domains->availability('example.com'),
            'GET', '/domains/availability', 'name=example.com', null, false,
        ];
        yield 'list_domains' => [static fn (Client $c) => $c->domains->list(['status' => 'active']), 'GET', '/domains', 'status=active', null, false];
        yield 'register_domain' => [
            static fn (Client $c) => $c->domains->register(['name' => 'example.com', 'years' => 1, 'privacy' => true]),
            'POST', '/domains', '', ['name' => 'example.com', 'years' => 1, 'privacy' => true], true,
        ];
        yield 'get_domain' => [static fn (Client $c) => $c->domains->get(77), 'GET', '/domains/77', '', null, false];
        yield 'update_domain' => [
            static fn (Client $c) => $c->domains->update(77, ['privacy' => false]),
            'PATCH', '/domains/77', '', ['privacy' => false], false,
        ];
        yield 'renew_domain' => [static fn (Client $c) => $c->domains->renew(77, 2), 'POST', '/domains/77/renew', '', ['years' => 2], true];
        yield 'set_domain_nameservers' => [
            static fn (Client $c) => $c->domains->setNameservers(77, ['ns1.example.net', ' ns2.example.net ']),
            'PUT', '/domains/77/nameservers', '', ['nameservers' => ['ns1.example.net', 'ns2.example.net']], false,
        ];
        yield 'transfer_domain' => [
            static fn (Client $c) => $c->domains->transfer(['name' => 'example.org', 'auth_code' => 'AbC-123-xyz']),
            'POST', '/domains/transfers', '', ['name' => 'example.org', 'auth_code' => 'AbC-123-xyz'], true,
        ];

        // Keys
        yield 'list_api_keys' => [static fn (Client $c) => $c->keys->list(), 'GET', '/keys', '', null, false];
        yield 'get_api_key' => [static fn (Client $c) => $c->keys->get(3), 'GET', '/keys/3', '', null, false];
        yield 'revoke_api_key' => [static fn (Client $c) => $c->keys->revoke(3), 'DELETE', '/keys/3', '', null, false];

        // Webhooks
        yield 'list_webhooks' => [static fn (Client $c) => $c->webhooks->list(), 'GET', '/webhooks', '', null, false];
        yield 'create_webhook' => [
            static fn (Client $c) => $c->webhooks->create(['url' => 'https://hooks.example.com/vdsok', 'events' => ['server.created', 'invoice.paid'], 'description' => 'billing sync']),
            'POST', '/webhooks', '', ['url' => 'https://hooks.example.com/vdsok', 'events' => ['server.created', 'invoice.paid'], 'description' => 'billing sync'], false,
        ];
        yield 'list_webhook_events' => [static fn (Client $c) => $c->webhooks->events(), 'GET', '/webhooks/events', '', null, false];
        yield 'get_webhook' => [static fn (Client $c) => $c->webhooks->get(5), 'GET', '/webhooks/5', '', null, false];
        yield 'update_webhook' => [static fn (Client $c) => $c->webhooks->update(5, ['active' => true]), 'PATCH', '/webhooks/5', '', ['active' => true], false];
        yield 'delete_webhook' => [static fn (Client $c) => $c->webhooks->delete(5), 'DELETE', '/webhooks/5', '', null, false];
        yield 'rotate_webhook_secret' => [static fn (Client $c) => $c->webhooks->rotateSecret(5), 'POST', '/webhooks/5/rotate-secret', '', null, false];
        yield 'test_webhook' => [static fn (Client $c) => $c->webhooks->test(5), 'POST', '/webhooks/5/test', '', null, false];
        yield 'list_webhook_deliveries' => [
            static fn (Client $c) => $c->webhooks->deliveries(5, ['status' => 'dead', 'event_type' => 'invoice.paid']),
            'GET', '/webhooks/5/deliveries', 'status=dead&event_type=invoice.paid', null, false,
        ];
        yield 'get_webhook_delivery' => [static fn (Client $c) => $c->webhooks->delivery(9), 'GET', '/webhooks/deliveries/9', '', null, false];
        yield 'redeliver_webhook' => [static fn (Client $c) => $c->webhooks->redeliver(9), 'POST', '/webhooks/deliveries/9/redeliver', '', null, false];
    }

    #[DataProvider('operations')]
    public function testOperationBuildsTheRightRequest(
        \Closure $call,
        string $method,
        string $path,
        string $query,
        ?array $body,
        bool $idempotent,
    ): void {
        $this->respond(200, ['data' => [], 'ok' => true]);
        $call($this->client());

        $req = $this->lastRequest();
        self::assertSame($method, $req->getMethod());
        self::assertSame('/api/v1' . $path, $req->getUri()->getPath());
        self::assertSame($query, $req->getUri()->getQuery());
        self::assertSame($body, $this->decodeBody($req));
        if ($body === null) {
            self::assertFalse($req->hasHeader('Content-Type'), 'no body → no Content-Type');
        } else {
            self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        }
        self::assertSame($idempotent, $req->hasHeader('Idempotency-Key'));
        if ($idempotent) {
            self::assertTrue(Uuid::isV4($req->getHeaderLine('Idempotency-Key')));
        }
        self::assertCount(1, $this->requests());
    }

    public function testInvalidPowerActionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->servers->actions->power(2001, 'reboot');
    }

    public function testRenewRequiresAPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->servers->renew(2001, []);
    }

    public function testDomainRenewYearsAreBounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->domains->renew(77, 11);
    }

    public function testNameserversNeedAtLeastTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->domains->setNameservers(77, ['ns1.example.net']);
    }

    /**
     * Спека требует uniqueItems. Дубли схлопываются до отправки, поэтому
     * два одинаковых NS — это «меньше двух», а не 400 по сети.
     */
    public function testDuplicateNameserversAreRejectedLocally(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->domains->setNameservers(77, ['ns1.example.net', ' ns1.example.net ']);
    }

    public function testNameserversAreDeduplicatedBeforeSending(): void
    {
        $this->respond(200, []);

        $this->client()->domains->setNameservers(77, [
            'ns1.example.net',
            'ns2.example.net',
            'ns1.example.net',
        ]);

        self::assertSame(
            ['nameservers' => ['ns1.example.net', 'ns2.example.net']],
            $this->decodeBody($this->lastRequest()),
        );
    }

    public function testTooManyNameserversAreRejected(): void
    {
        // Ровно два: третий NS схема не хранит, и отправить его значило бы
        // молча потерять (ручка на такой список отвечает 400).
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->domains->setNameservers(77, ['a.net', 'b.net', 'c.net']);
    }

    public function testQuoteRequiresTariff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->catalog->quote(['months' => 1]);
    }

    /** Пустое значение — это «снять запись», а не ошибка клиента. */
    public function testEmptyPtrRemovesTheRecord(): void
    {
        $this->respond(200, ['id' => 501, 'ptr' => null]);
        $res = $this->client()->servers->ips->setPtr(2001, 501, '  ');
        self::assertSame(['domain' => ''], $this->decodeBody($this->lastRequest()));
        self::assertNull($res->get('ptr'));
        self::assertSame(501, $res->get('id'));
    }

    public function testRevokeCurrentLooksUpTheKeyFirst(): void
    {
        $this->respond(200, ['account_id' => 57, 'livemode' => true, 'key' => ['id' => 3]]);
        // DELETE /keys/{id} отдаёт {"status": "revoked", "key": {...}}.
        $this->respond(200, ['status' => 'revoked', 'key' => ['id' => 3, 'active' => false]]);

        $res = $this->client()->keys->revokeCurrent();

        self::assertSame('revoked', $res['status']);
        self::assertFalse($res->get('key.active'));
        [$me, $revoke] = $this->requests();
        self::assertSame('/api/v1/me', $me->getUri()->getPath());
        self::assertSame('DELETE', $revoke->getMethod());
        self::assertSame('/api/v1/keys/3', $revoke->getUri()->getPath());
    }

    public function testTopupNormalisesTheAmount(): void
    {
        $this->respond(201, []);
        $this->client()->balance->topup(['amount' => 25, 'gateway' => 'cryptobot']);
        self::assertSame(['amount' => '25.00', 'gateway' => 'cryptobot'], $this->decodeBody($this->lastRequest()));
    }
}
