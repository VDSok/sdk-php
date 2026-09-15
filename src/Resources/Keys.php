<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;

/** API-ключи аккаунта. Скоуп `keys:read`; отзыв собственного ключа — без скоупа. */
final class Keys extends AbstractResource
{
    /**
     * `GET /keys` — ключи аккаунта (секрет никогда не возвращается).
     *
     * Ответ — конверт страницы `{"data": [...], "next_cursor": null}`: курсора
     * сегодня нет, но поле есть, и читать его страницей безопаснее.
     */
    public function list(): Page
    {
        return $this->transport->page('GET', '/keys');
    }

    /** `GET /keys/{id}` */
    public function get(int|string $keyId): ApiResponse
    {
        return $this->transport->request('GET', '/keys/' . self::id($keyId, 'key_id'));
    }

    /**
     * `DELETE /keys/{id}` — отозвать ключ. Сервер разрешает отозвать только
     * тот ключ, которым сделан запрос (kill switch при утечке); чужие
     * ключи отзываются в кабинете (иначе `403 insufficient_scope`).
     *
     * Ответ: `{"status": "revoked", "key": {...}}` — сам ключ лежит в `key`.
     */
    public function revoke(int|string $keyId): ApiResponse
    {
        return $this->transport->request('DELETE', '/keys/' . self::id($keyId, 'key_id'));
    }

    /** Отозвать ключ, которым работает этот клиент: `GET /me` → `DELETE /keys/{key.id}`. */
    public function revokeCurrent(): ApiResponse
    {
        $me = $this->transport->request('GET', '/me');
        $keyId = $me->get('key.id');
        if (!is_int($keyId) && !(is_string($keyId) && ctype_digit($keyId))) {
            throw new \UnexpectedValueException('GET /me did not return key.id');
        }

        return $this->revoke($keyId);
    }
}
