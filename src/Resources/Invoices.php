<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\BinaryResponse;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/** Счета. Скоупы `invoices:read`, `invoices:pay`. */
final class Invoices extends AbstractResource
{
    /**
     * `GET /invoices` — страница счетов, новые первыми.
     *
     * @param array{cursor?: string, limit?: int, status?: string, type?: string} $query
     */
    public function list(array $query = []): Page
    {
        return $this->transport->page('GET', '/invoices', $query);
    }

    public function iterate(array $query = []): Paginator
    {
        return $this->paginate('/invoices', $query);
    }

    /** `GET /invoices/{id}` — счёт с позициями. */
    public function get(int|string $invoiceId): ApiResponse
    {
        return $this->transport->request('GET', '/invoices/' . self::id($invoiceId, 'invoice_id'));
    }

    /** `GET /invoices/{id}/pdf` — PDF счёта (дорогой вызов). */
    public function pdf(int|string $invoiceId): BinaryResponse
    {
        return $this->transport->binary('GET', '/invoices/' . self::id($invoiceId, 'invoice_id') . '/pdf');
    }

    /**
     * `POST /invoices/{id}/pay` — оплатить с баланса.
     *
     * Успех ровно один: `200` с `{"status": "paid", …}`. Если счёт заказывал
     * сервер, тот разворачивается фоном уже после ответа — следите за ним
     * через `$client->servers->orders->get($invoiceId)` или вебхук
     * `server.created`, а не по коду ответа.
     */
    public function pay(int|string $invoiceId, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->transport->request(
            'POST',
            '/invoices/' . self::id($invoiceId, 'invoice_id') . '/pay',
            [],
            null,
            self::idempotent($idempotencyKey),
        );
    }

    /**
     * `POST /invoices/{id}/payment-link` — ссылка на оплату через шлюз.
     *
     * Без `gateway` берётся тот, что уже записан в счёте (продолжение
     * начатой оплаты). Других полей ручка не принимает.
     *
     * @param array{gateway?: string} $params
     */
    public function paymentLink(int|string $invoiceId, array $params = []): ApiResponse
    {
        return $this->transport->request(
            'POST',
            '/invoices/' . self::id($invoiceId, 'invoice_id') . '/payment-link',
            [],
            $params === [] ? null : $params,
        );
    }
}
