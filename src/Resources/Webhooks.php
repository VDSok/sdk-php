<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/**
 * Подписки на вебхуки. Скоуп `webhooks:manage`, только live-ключи
 * (тестовый ключ получит `403 sandbox_not_supported`).
 * Проверка подписи входящих доставок — в `\Vdsok\Sdk\Webhooks`.
 */
final class Webhooks extends AbstractResource
{
    /**
     * `GET /webhooks` — все подписки.
     *
     * Как и `/keys`, ответ — конверт страницы `{"data": [...],
     * "next_cursor": null}`; курсора сегодня нет, поле есть.
     */
    public function list(): Page
    {
        return $this->transport->page('GET', '/webhooks');
    }

    /**
     * `POST /webhooks` — подписать URL на события. `secret` возвращается
     * один раз — сохраните его для `Webhooks::verify()`.
     *
     * @param array{url: string, events: string[], description?: string} $params
     */
    public function create(array $params): ApiResponse
    {
        if (empty($params['url']) || empty($params['events'])) {
            throw new \InvalidArgumentException('create() requires url and events');
        }

        return $this->transport->request('POST', '/webhooks', [], $params);
    }

    /** `GET /webhooks/events` — каталог типов событий: `{type, description}`. */
    public function events(): Page
    {
        return $this->transport->page('GET', '/webhooks/events');
    }

    /** `GET /webhooks/{id}` */
    public function get(int|string $webhookId): ApiResponse
    {
        return $this->transport->request('GET', '/webhooks/' . self::id($webhookId, 'webhook_id'));
    }

    /**
     * `PATCH /webhooks/{id}` — URL, события, описание; `active: true`
     * включает подписку после автоотключения. `null` в любом поле читается
     * сервером как «не трогать», а не как «сбросить».
     *
     * @param array{url?: string, events?: string[], description?: string, active?: bool} $params
     */
    public function update(int|string $webhookId, array $params): ApiResponse
    {
        return $this->transport->request('PATCH', '/webhooks/' . self::id($webhookId, 'webhook_id'), [], $params);
    }

    /**
     * `DELETE /webhooks/{id}` — удаляется и журнал доставок.
     *
     * Ответ — `200` с телом `{"status": "deleted", "id": N}`, не пустой `204`.
     */
    public function delete(int|string $webhookId): ApiResponse
    {
        return $this->transport->request('DELETE', '/webhooks/' . self::id($webhookId, 'webhook_id'));
    }

    /**
     * `POST /webhooks/{id}/rotate-secret` — новый секрет; старый перестаёт
     * работать сразу. Ответ — только `{"id": N, "secret": "whsec_…"}`:
     * сама подписка не меняется, перечитывать её не нужно.
     */
    public function rotateSecret(int|string $webhookId): ApiResponse
    {
        return $this->transport->request('POST', '/webhooks/' . self::id($webhookId, 'webhook_id') . '/rotate-secret');
    }

    /**
     * `POST /webhooks/{id}/test` — отправить `ping` сейчас и вернуть результат
     * (дорогой вызов): `{delivery, ok, status, latency_ms, detail}`.
     * Выключенная подписка → `409 conflict`.
     */
    public function test(int|string $webhookId): ApiResponse
    {
        return $this->transport->request('POST', '/webhooks/' . self::id($webhookId, 'webhook_id') . '/test');
    }

    /**
     * `GET /webhooks/{id}/deliveries` — журнал доставок, новые первыми.
     * Отдельного `status` у строки нет: `dead` — попыток больше не будет,
     * `delivered_at` — доставлено, иначе ждёт `next_attempt_at`. Фильтр
     * `status` принимает `delivered|pending|dead`.
     *
     * @param array{cursor?: string, limit?: int, status?: string, event_type?: string} $query
     */
    public function deliveries(int|string $webhookId, array $query = []): Page
    {
        return $this->transport->page('GET', '/webhooks/' . self::id($webhookId, 'webhook_id') . '/deliveries', $query);
    }

    public function iterateDeliveries(int|string $webhookId, array $query = []): Paginator
    {
        return $this->paginate('/webhooks/' . self::id($webhookId, 'webhook_id') . '/deliveries', $query);
    }

    /**
     * `GET /webhooks/deliveries/{delivery_id}` — одна доставка вместе с
     * `payload` (единственная ручка, которая его отдаёт).
     */
    public function delivery(int|string $deliveryId): ApiResponse
    {
        return $this->transport->request('GET', '/webhooks/deliveries/' . self::id($deliveryId, 'delivery_id'));
    }

    /**
     * `POST /webhooks/deliveries/{delivery_id}/redeliver` — поставить доставку
     * в очередь заново (те же байты). Ответ `202`:
     * `{"status": "queued", "delivery": {...}}`.
     */
    public function redeliver(int|string $deliveryId): ApiResponse
    {
        return $this->transport->request('POST', '/webhooks/deliveries/' . self::id($deliveryId, 'delivery_id') . '/redeliver');
    }
}
