<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/** Домены. Скоупы `domains:read`, `domains:manage`, `domains:order`. */
final class Domains extends AbstractResource
{
    /** `GET /domains/availability?name=` — свободно ли имя и почём (дорогой вызов). */
    public function availability(string $name): ApiResponse
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Domain name must not be empty');
        }

        return $this->transport->request('GET', '/domains/availability', ['name' => $name]);
    }

    /**
     * `GET /domains` — страница доменов.
     *
     * @param array{cursor?: string, limit?: int, status?: string} $query
     */
    public function list(array $query = []): Page
    {
        return $this->transport->page('GET', '/domains', $query);
    }

    public function iterate(array $query = []): Paginator
    {
        return $this->paginate('/domains', $query);
    }

    /**
     * `POST /domains` — зарегистрировать. `201` — регистратор подтвердил,
     * `202` (`isAccepted()`) — принято, станет `active` на следующей
     * синхронизации (событие `domain.registered`). Идемпотентно.
     *
     * `auto_renew` регистрация не принимает — флаг ставится отдельным
     * `PATCH /domains/{id}` уже после покупки.
     *
     * @param array{name: string, years?: int, nameservers?: string[], privacy?: bool, promo_code?: string} $params
     */
    public function register(array $params, ?string $idempotencyKey = null): ApiResponse
    {
        if (empty($params['name'])) {
            throw new \InvalidArgumentException('register() requires name');
        }

        return $this->transport->request('POST', '/domains', [], $params, self::idempotent($idempotencyKey));
    }

    /** `GET /domains/{id}` */
    public function get(int|string $domainId): ApiResponse
    {
        return $this->transport->request('GET', '/domains/' . self::id($domainId, 'domain_id'));
    }

    /**
     * `PATCH /domains/{id}` — автопродление и WHOIS-приватность.
     *
     * @param array{auto_renew?: bool, privacy?: bool} $params
     */
    public function update(int|string $domainId, array $params): ApiResponse
    {
        return $this->transport->request('PATCH', '/domains/' . self::id($domainId, 'domain_id'), [], $params);
    }

    /** `POST /domains/{id}/renew` — продлить на N лет с баланса. Идемпотентно. */
    public function renew(int|string $domainId, int $years, ?string $idempotencyKey = null): ApiResponse
    {
        if ($years < 1 || $years > 10) {
            throw new \InvalidArgumentException('years must be 1..10');
        }

        return $this->transport->request(
            'POST',
            '/domains/' . self::id($domainId, 'domain_id') . '/renew',
            [],
            ['years' => $years],
            self::idempotent($idempotencyKey),
        );
    }

    /**
     * `PUT /domains/{id}/nameservers` — заменить набор NS (ровно два
     * уникальных: схема хранит ns1/ns2, третий NS просто пропал бы).
     *
     * @param list<string> $nameservers
     */
    public function setNameservers(int|string $domainId, array $nameservers): ApiResponse
    {
        // uniqueItems из спеки проверяем локально: дубль NS — частая опечатка,
        // и ловить её ответом 400 через сеть незачем.
        $nameservers = array_values(array_unique(array_map('trim', $nameservers)));
        if (count($nameservers) !== 2) {
            throw new \InvalidArgumentException('nameservers must contain exactly 2 unique entries');
        }

        return $this->transport->request(
            'PUT',
            '/domains/' . self::id($domainId, 'domain_id') . '/nameservers',
            [],
            ['nameservers' => $nameservers],
        );
    }

    /**
     * `POST /domains/transfers` — перенести домен от другого регистратора.
     * Ответ `202`, домен появится со статусом `transfer_pending`. Идемпотентно.
     *
     * @param array{name: string, auth_code: string, nameservers?: string[]} $params
     */
    public function transfer(array $params, ?string $idempotencyKey = null): ApiResponse
    {
        if (empty($params['name']) || empty($params['auth_code'])) {
            throw new \InvalidArgumentException('transfer() requires name and auth_code');
        }

        return $this->transport->request('POST', '/domains/transfers', [], $params, self::idempotent($idempotencyKey));
    }
}
