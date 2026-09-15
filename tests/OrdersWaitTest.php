<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Vdsok\Sdk\Exception\TimeoutError;

final class OrdersWaitTest extends TestCase
{
    public function testPollsUntilTerminalState(): void
    {
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'provisioning', 'server_id' => null]);
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'provisioning', 'server_id' => null]);
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'active', 'server_id' => 2002]);

        $order = $this->client()->servers->orders->wait(10231, 600, 5);

        self::assertSame('active', $order['status']);
        self::assertSame(2002, $order['server_id']);
        self::assertCount(3, $this->requests());
        self::assertSame([5.0, 5.0], $this->sleeps);
        foreach ($this->requests() as $req) {
            self::assertSame('/api/v1/orders/10231', $req->getUri()->getPath());
        }
    }

    /** Сорвавшийся заказ возвращает деньги и приходит как `cancelled`. */
    public function testCancelledIsTerminalToo(): void
    {
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'cancelled', 'server_id' => null]);
        $order = $this->client()->servers->orders->wait(10231);
        self::assertSame('cancelled', $order['status']);
        self::assertSame([], $this->sleeps);
    }

    /** Неизвестный статус тоже считается конечным, если API когда-нибудь его
     * вернёт: опрашивать вечно хуже, чем остановиться. */
    public function testFailedIsTerminalToo(): void
    {
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'failed', 'failure_reason' => 'no capacity']);
        $order = $this->client()->servers->orders->wait(10231);
        self::assertSame('failed', $order['status']);
        self::assertSame([], $this->sleeps);
    }

    public function testTimesOutWithLastState(): void
    {
        $this->respond(200, ['invoice_id' => 10231, 'status' => 'provisioning']);
        try {
            $this->client()->servers->orders->wait(10231, 0, 5);
            self::fail('expected TimeoutError');
        } catch (TimeoutError $e) {
            self::assertSame('provisioning', $e->lastState['status']);
            self::assertStringContainsString('10231', $e->getMessage());
        }
        self::assertCount(1, $this->requests());
        self::assertSame([], $this->sleeps);
    }

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->servers->orders->wait(10231, 10, 0);
    }
}
