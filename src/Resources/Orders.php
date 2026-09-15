<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Exception\TimeoutError;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/** Заказы серверов (после `202 provisioning`). Скоуп `servers:read`. */
final class Orders extends AbstractResource
{
    // `failed` API сегодня не отдаёт (сорвавшийся заказ возвращает деньги и
    // приходит как `cancelled`), но терпеть неизвестный статус дешевле, чем
    // вечно опрашивать заказ, который уже никуда не двинется.
    public const TERMINAL_STATUSES = ['active', 'cancelled', 'failed'];

    /**
     * `GET /orders` — страница заказов, новые первыми.
     *
     * @param array{cursor?: string, limit?: int, status?: string} $query
     */
    public function list(array $query = []): Page
    {
        return $this->transport->page('GET', '/orders', $query);
    }

    public function iterate(array $query = []): Paginator
    {
        return $this->paginate('/orders', $query);
    }

    /** `GET /orders/{invoice_id}` — состояние заказа по id его счёта. */
    public function get(int|string $invoiceId): ApiResponse
    {
        return $this->transport->request('GET', '/orders/' . self::id($invoiceId, 'invoice_id'));
    }

    /**
     * Опрашивает заказ, пока он не станет `active`, `failed` или `cancelled`.
     * Между опросами — `$interval` секунд (через sleeper транспорта), всего
     * не дольше `$timeout` секунд; по истечении — TimeoutError с последним
     * состоянием в `lastState`. Заказ при этом продолжает выполняться.
     */
    public function wait(int|string $invoiceId, float $timeout = 600.0, float $interval = 5.0): ApiResponse
    {
        if ($interval <= 0) {
            throw new \InvalidArgumentException('interval must be > 0');
        }
        $deadline = microtime(true) + $timeout;
        while (true) {
            $order = $this->get($invoiceId);
            if (in_array($order['status'] ?? null, self::TERMINAL_STATUSES, true)) {
                return $order;
            }
            if (microtime(true) >= $deadline) {
                throw new TimeoutError(
                    sprintf('Order %s is still "%s" after %.0f s', (string) $invoiceId, (string) ($order['status'] ?? '?'), $timeout),
                    $order,
                );
            }
            $this->transport->sleep($interval);
        }
    }
}
