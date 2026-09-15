<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * RFC 3339 в UTC с `Z` на конце — единственный формат дат в API.
 *
 * Разбор строгий (регулярное выражение из спецификации), а не
 * `new DateTimeImmutable($s)`: свободный разбор молча принял бы локальные
 * даты без зоны и подставил бы часовой пояс сервера приложения.
 */
final class Timestamp
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?Z$/';

    /** @throws \InvalidArgumentException если строка не соответствует формату API */
    public static function parse(string $value): \DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new \InvalidArgumentException("Not an RFC 3339 UTC timestamp: {$value}");
        }
        // createFromFormat молча «перекатывает» 13-й месяц в следующий год,
        // поэтому диапазоны проверяем сами.
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            throw new \InvalidArgumentException("Invalid date components in timestamp: {$value}");
        }
        // Дробная часть может быть длиннее 6 знаков (наносекунды), а PHP
        // хранит только микросекунды — усекаем, не округляя.
        $fraction = isset($m[7]) && $m[7] !== '' ? substr($m[7] . '000000', 1, 6) : '000000';
        $dt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            sprintf('%s-%s-%s %s:%s:%s.%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $fraction),
            new \DateTimeZone('UTC'),
        );
        if ($dt === false) {
            throw new \InvalidArgumentException("Invalid date components in timestamp: {$value}");
        }

        return $dt;
    }

    /** null остаётся null; всё остальное — как `parse()`. */
    public static function parseNullable(?string $value): ?\DateTimeImmutable
    {
        return $value === null ? null : self::parse($value);
    }

    /** Обратное преобразование для query-параметров `since`/`until`. Микросекунды сохраняются, если ненулевые. */
    public static function format(\DateTimeInterface $value): string
    {
        $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        $micro = $utc->format('u');

        return $micro === '000000'
            ? $utc->format('Y-m-d\TH:i:s\Z')
            : $utc->format('Y-m-d\TH:i:s') . '.' . rtrim($micro, '0') . 'Z';
    }
}
