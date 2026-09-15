<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;

/** Профиль аккаунта. Скоуп `account:read`. */
final class Account extends AbstractResource
{
    /** `GET /account` — профиль, клиентская группа и скидка. */
    public function get(): ApiResponse
    {
        return $this->transport->request('GET', '/account');
    }
}
