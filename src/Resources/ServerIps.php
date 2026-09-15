<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;

/** Адреса сервера и PTR. Скоупы `servers:read`, `servers:order`, `servers:manage`. */
final class ServerIps extends AbstractResource
{
    /** `GET /servers/{id}/ips` — все адреса сервера (без пагинации). */
    public function list(int|string $serverId): Page
    {
        return $this->transport->page('GET', '/servers/' . self::id($serverId, 'server_id') . '/ips');
    }

    /** `GET /servers/{id}/ips/quote` — цена ещё одного IPv4 для этого сервера. */
    public function quote(int|string $serverId): ApiResponse
    {
        return $this->transport->request('GET', '/servers/' . self::id($serverId, 'server_id') . '/ips/quote');
    }

    /**
     * `POST /servers/{id}/ips` — купить дополнительный IPv4. Списывает
     * пропорциональную цену до конца периода. Идемпотентно.
     *
     * Самого адреса в ответе нет: панель выдаёт его асинхронно — читайте
     * его через `list()`. В ответе `success`, `server_id`, `charged`,
     * `currency` и `days`.
     */
    public function add(int|string $serverId, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->transport->request(
            'POST',
            '/servers/' . self::id($serverId, 'server_id') . '/ips',
            [],
            null,
            self::idempotent($idempotencyKey),
        );
    }

    /** `DELETE /servers/{id}/ips/{ip_id}` — освободить дополнительный адрес (без возврата денег). */
    public function delete(int|string $serverId, int|string $ipId): ApiResponse
    {
        return $this->transport->request(
            'DELETE',
            '/servers/' . self::id($serverId, 'server_id') . '/ips/' . self::id($ipId, 'ip_id'),
        );
    }

    /**
     * `PUT /servers/{id}/ips/{ip_id}/ptr` — установить или снять PTR.
     *
     * Поле тела зовётся `domain`: ручка принимает только его
     * (api_v1/routes/ips.py:224) и на `ptr` отвечает `400`. Пустая строка и
     * `null` снимают запись — поэтому пустое значение больше не исключение.
     * Ответ — `{id, ptr}`, а не карточка адреса.
     */
    public function setPtr(int|string $serverId, int|string $ipId, ?string $ptr): ApiResponse
    {
        return $this->transport->request(
            'PUT',
            '/servers/' . self::id($serverId, 'server_id') . '/ips/' . self::id($ipId, 'ip_id') . '/ptr',
            [],
            ['domain' => trim((string) $ptr)],
        );
    }
}
