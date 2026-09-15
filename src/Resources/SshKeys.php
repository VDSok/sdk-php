<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;

/** Публичные SSH-ключи аккаунта. Скоупы `servers:read`, `servers:manage`. */
final class SshKeys extends AbstractResource
{
    /** `GET /ssh-keys` — все ключи (без пагинации). */
    public function list(): Page
    {
        return $this->transport->page('GET', '/ssh-keys');
    }

    /** `POST /ssh-keys` — добавить ключ в формате OpenSSH (одна строка). */
    public function create(string $name, string $publicKey): ApiResponse
    {
        $name = trim($name);
        $publicKey = trim($publicKey);
        if ($name === '' || $publicKey === '') {
            throw new \InvalidArgumentException('name and public_key must not be empty');
        }

        return $this->transport->request('POST', '/ssh-keys', [], ['name' => $name, 'public_key' => $publicKey]);
    }

    /** `DELETE /ssh-keys/{id}` — ответ `204`; серверы, где ключ уже установлен, не трогаются. */
    public function delete(int|string $keyId): ApiResponse
    {
        return $this->transport->request('DELETE', '/ssh-keys/' . self::id($keyId, 'key_id'));
    }
}
