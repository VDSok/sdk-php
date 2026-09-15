<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;

/**
 * Питание, переустановка, сброс пароля. Скоуп `servers:manage`.
 * Эти операции НЕ идемпотентны и не повторяются SDK автоматически:
 * второй `restart` после таймаута — это второй рестарт.
 */
final class ServerActions extends AbstractResource
{
    public const POWER_ACTIONS = ['start', 'stop', 'restart'];

    /** `POST /servers/{id}/actions/power` — `start` | `stop` | `restart`; ответ `202`. */
    public function power(int|string $serverId, string $action): ApiResponse
    {
        if (!in_array($action, self::POWER_ACTIONS, true)) {
            throw new \InvalidArgumentException('Power action must be one of ' . implode(', ', self::POWER_ACTIONS));
        }

        return $this->transport->request(
            'POST',
            '/servers/' . self::id($serverId, 'server_id') . '/actions/power',
            [],
            ['action' => $action],
        );
    }

    public function start(int|string $serverId): ApiResponse
    {
        return $this->power($serverId, 'start');
    }

    public function stop(int|string $serverId): ApiResponse
    {
        return $this->power($serverId, 'stop');
    }

    public function restart(int|string $serverId): ApiResponse
    {
        return $this->power($serverId, 'restart');
    }

    /**
     * `POST /servers/{id}/actions/reinstall` — переустановить ОС. Уничтожает
     * данные на диске. Без `password` новый пароль генерируется и
     * возвращается один раз в `root_password`.
     *
     * @param array{os: string, password?: string, ssh_key_ids?: int[]} $params
     */
    public function reinstall(int|string $serverId, array $params): ApiResponse
    {
        if (empty($params['os'])) {
            throw new \InvalidArgumentException('reinstall() requires os');
        }

        return $this->transport->request(
            'POST',
            '/servers/' . self::id($serverId, 'server_id') . '/actions/reinstall',
            [],
            $params,
        );
    }

    /** `POST /servers/{id}/actions/reset-password` — новый root-пароль, показывается один раз. */
    public function resetPassword(int|string $serverId): ApiResponse
    {
        return $this->transport->request(
            'POST',
            '/servers/' . self::id($serverId, 'server_id') . '/actions/reset-password',
        );
    }
}
