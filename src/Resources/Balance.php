<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Money;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/** Баланс, транзакции, пополнение. Скоупы `balance:read`, `balance:topup`. */
final class Balance extends AbstractResource
{
    /** `GET /balance` — баланс и предстоящие списания. */
    public function get(): ApiResponse
    {
        return $this->transport->request('GET', '/balance');
    }

    /**
     * `GET /transactions` — одна страница транзакций, новые первыми.
     *
     * @param array{cursor?: string, limit?: int, direction?: string, since?: string|\DateTimeInterface, until?: string|\DateTimeInterface} $query
     */
    public function transactions(array $query = []): Page
    {
        return $this->transport->page('GET', '/transactions', $query);
    }

    /** Ленивый обход всех транзакций с теми же фильтрами. */
    public function iterateTransactions(array $query = []): Paginator
    {
        return $this->paginate('/transactions', $query);
    }

    /**
     * `GET /balance/topup-info` — лимиты и доступные шлюзы.
     *
     * Ответ: `{min, max, currency, first_topup_bonus_percent,
     * first_topup_eligible, gateways}`, где `gateways` — плоский список
     * кодов (`["cryptobot", …]`), а не объектов: код передаётся в `topup()`
     * как есть.
     */
    public function topupInfo(): ApiResponse
    {
        return $this->transport->request('GET', '/balance/topup-info');
    }

    /**
     * `POST /balance/topup` — создать счёт на пополнение и получить ссылку на оплату.
     * Идемпотентно: ключ генерируется автоматически, доступен в `$result->idempotencyKey`.
     *
     * Тело — ровно два поля: любое другое ручка отвергает `400`.
     *
     * @param array{amount: string|int|float, gateway: string} $params
     */
    public function topup(array $params, ?string $idempotencyKey = null): ApiResponse
    {
        if (isset($params['amount'])) {
            $params['amount'] = Money::format($params['amount']);
        }

        return $this->transport->request('POST', '/balance/topup', [], $params, self::idempotent($idempotencyKey));
    }
}
