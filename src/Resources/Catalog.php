<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\ApiResponse;
use Vdsok\Sdk\Page;

/** Каталог: тарифы, ОС, локации, зоны доменов, расчёт цены. Любой валидный ключ, скоуп не нужен. */
final class Catalog extends AbstractResource
{
    /** `GET /catalog/tariffs` — VDS-тарифы с учётом скидки группы вызывающего. */
    public function tariffs(?int $locationId = null): Page
    {
        return $this->transport->page('GET', '/catalog/tariffs', ['location_id' => $locationId]);
    }

    /** `GET /catalog/tariffs/{id}` */
    public function tariff(int|string $tariffId): ApiResponse
    {
        return $this->transport->request('GET', '/catalog/tariffs/' . self::id($tariffId, 'tariff_id'));
    }

    /** `GET /catalog/os` — образы ОС; с `tariff_id` без запрещённых для тарифа. */
    public function osImages(?int $tariffId = null): Page
    {
        return $this->transport->page('GET', '/catalog/os', ['tariff_id' => $tariffId]);
    }

    /** `GET /catalog/locations` */
    public function locations(): Page
    {
        return $this->transport->page('GET', '/catalog/locations');
    }

    /** `GET /catalog/zones` — TLD и цены. */
    public function zones(): Page
    {
        return $this->transport->page('GET', '/catalog/zones');
    }

    /**
     * `GET /catalog/quote` — цена заказа до его оформления (дорогой вызов).
     * Укажите `months` или `hours`; промокод проверяется, но не тратится.
     *
     * @param array{tariff_id: int, months?: int, hours?: int, billing_cycle?: string, promo_code?: string} $query
     */
    public function quote(array $query): ApiResponse
    {
        if (!isset($query['tariff_id'])) {
            throw new \InvalidArgumentException('quote() requires tariff_id');
        }

        return $this->transport->request('GET', '/catalog/quote', $query);
    }
}
