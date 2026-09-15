<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Http\Transport;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/**
 * Серверы (VDS). Скоупы `servers:read`, `servers:manage`, `servers:order`,
 * `servers:delete`. Подгруппы: `actions` (питание, переустановка, пароль),
 * `ips` (дополнительные IPv4 и PTR), `orders` (заказы после `202`).
 */
final class Servers extends AbstractResource
{
    public readonly ServerActions $actions;
    public readonly ServerIps $ips;
    public readonly Orders $orders;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->actions = new ServerActions($transport);
        $this->ips = new ServerIps($transport);
        $this->orders = new Orders($transport);
    }

    /**
     * `GET /servers` — страница серверов. `ip` ищет по любому из адресов.
     *
     * @param array{cursor?: string, limit?: int, status?: string, ip?: string} $query
     */
    public function list(array $query = []): Page
    {
        return $this->transport->page('GET', '/servers', $query);
    }

    public function iterate(array $query = []): Paginator
    {
        return $this->paginate('/servers', $query);
    }

    /** Сервер по IP-адресу или null. */
    public function findByIp(string $ip): ?array
    {
        return $this->list(['ip' => $ip, 'limit' => 1])->first();
    }

    /**
     * `POST /servers` — заказать сервер. `201` — создан, `root_password` в
     * ответе показывается один раз; `202` (`$res->isAccepted()`) — деньги
     * списаны, VM ещё создаётся: `$client->servers->orders->wait($res['invoice_id'])`.
     * Идемпотентно: ключ генерируется автоматически и доступен в
     * `$res->idempotencyKey` / `$error->idempotencyKey` для повтора.
     *
     * @param array{tariff_id: int, os: string, name?: string, password?: string, months?: int, hours?: int, billing_cycle?: string, promo_code?: string, ssh_key_ids?: int[], custom_fields?: array<string, string>} $params
     */
    public function create(array $params, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->transport->request('POST', '/servers', [], $params, self::idempotent($idempotencyKey));
    }

    /** `GET /servers/{id}` — детали с `refund_quote`; `$live = true` добавляет состояние из панели (дорого). */
    public function get(int|string $serverId, bool $live = false): ApiResponse
    {
        return $this->transport->request(
            'GET',
            '/servers/' . self::id($serverId, 'server_id'),
            ['include' => $live ? 'live' : null],
        );
    }

    /**
     * `PATCH /servers/{id}` — автопродление, имя, заметки.
     *
     * @param array{auto_renew?: bool, name?: string, notes?: string|null} $params
     */
    public function update(int|string $serverId, array $params): ApiResponse
    {
        return $this->transport->request('PATCH', '/servers/' . self::id($serverId, 'server_id'), [], $params);
    }

    /** Короткая форма `update(['auto_renew' => ...])`. */
    public function setAutoRenew(int|string $serverId, bool $enabled): ApiResponse
    {
        return $this->update($serverId, ['auto_renew' => $enabled]);
    }

    /**
     * `DELETE /servers/{id}` — удалить сервер и вернуть неиспользованные дни
     * на баланс. Идемпотентно.
     */
    public function delete(int|string $serverId, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->transport->request(
            'DELETE',
            '/servers/' . self::id($serverId, 'server_id'),
            [],
            null,
            self::idempotent($idempotencyKey),
        );
    }

    /** `GET /servers/{id}/status` — живое состояние из панели (дорогой вызов). */
    public function status(int|string $serverId): ApiResponse
    {
        return $this->transport->request('GET', '/servers/' . self::id($serverId, 'server_id') . '/status');
    }

    /** `GET /servers/{id}/refund-quote` — что вернёт DELETE прямо сейчас. */
    public function refundQuote(int|string $serverId): ApiResponse
    {
        return $this->transport->request('GET', '/servers/' . self::id($serverId, 'server_id') . '/refund-quote');
    }

    /**
     * `POST /servers/{id}/renew` — продлить с баланса. Идемпотентно.
     *
     * @param array{months?: int, hours?: int} $period `['months' => 3]` для помесячных, `['hours' => 24]` для почасовых
     */
    public function renew(int|string $serverId, array $period, ?string $idempotencyKey = null): ApiResponse
    {
        if (!isset($period['months']) && !isset($period['hours'])) {
            throw new \InvalidArgumentException('renew() requires months or hours');
        }

        return $this->transport->request(
            'POST',
            '/servers/' . self::id($serverId, 'server_id') . '/renew',
            [],
            $period,
            self::idempotent($idempotencyKey),
        );
    }
}
