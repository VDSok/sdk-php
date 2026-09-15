<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Деньги в API — десятичные СТРОКИ с 2–4 знаками после точки, никогда
 * float. SDK не превращает их в числа: `"0.0083"` во float потеряет
 * точность, а `"5.90"` станет `5.9`. Этот класс лишь проверяет формат
 * и помогает собрать строку из целого числа копеек/центов.
 */
final class Money
{
    public const PATTERN = '/^-?[0-9]+\.[0-9]{2,4}$/';

    public static function isValid(string $amount): bool
    {
        return preg_match(self::PATTERN, $amount) === 1;
    }

    /**
     * Нормализует сумму для тела запроса. Строки проверяются по шаблону
     * (`"25"` → `"25.00"`), целые — это единицы валюты (`25` → `"25.00"`).
     * Float принимаются, но округляются до `$scale` знаков — используйте
     * строки, если сумма пришла из вашей БД.
     *
     * @throws \InvalidArgumentException
     */
    public static function format(int|float|string $amount, int $scale = 2): string
    {
        if ($scale < 2 || $scale > 4) {
            throw new \InvalidArgumentException('Money scale must be 2..4');
        }
        if (is_int($amount)) {
            return sprintf('%d.%s', $amount, str_repeat('0', $scale));
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('Money amount must be finite');
            }

            return number_format($amount, $scale, '.', '');
        }
        $amount = trim($amount);
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/', $amount, $m) !== 1) {
            throw new \InvalidArgumentException("Not a decimal money string: {$amount}");
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }

        return $m[1] . $m[2] . '.' . $fraction;
    }

    /** Из минимальных единиц (центов) в строку API: `2500` → `"25.00"`. */
    public static function fromMinor(int $minor, int $scale = 2): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $div = 10 ** $scale;

        return sprintf('%s%d.%0' . $scale . 'd', $sign, intdiv($abs, $div), $abs % $div);
    }

    /** Из строки API в минимальные единицы: `"25.00"` → `2500`. Знаков сверх `$scale` быть не должно. */
    public static function toMinor(string $amount, int $scale = 2): int
    {
        if (preg_match('/^(-?)(\d+)\.(\d+)$/', $amount, $m) !== 1) {
            throw new \InvalidArgumentException("Not a decimal money string: {$amount}");
        }
        $fraction = $m[3];
        if (strlen($fraction) > $scale) {
            if (rtrim(substr($fraction, $scale), '0') !== '') {
                throw new \InvalidArgumentException("{$amount} has more than {$scale} fraction digits");
            }
            $fraction = substr($fraction, 0, $scale);
        }
        $fraction = str_pad($fraction, $scale, '0');
        $value = (int) $m[2] * (10 ** $scale) + (int) $fraction;

        return $m[1] === '-' ? -$value : $value;
    }

    /**
     * Сравнение без float: -1, 0, 1. Годится для «хватает ли баланса».
     *
     * Оба аргумента сначала прогоняются через `format()`, потому что типичный
     * вызов — сравнить сумму из API («25.00») с константой из конфига («100»):
     * `toMinor()` требует точку, и без нормализации это падало бы.
     */
    public static function compare(string $a, string $b): int
    {
        $a = self::format($a, 4);
        $b = self::format($b, 4);
        $scale = max(self::scaleOf($a), self::scaleOf($b), 2);

        return self::toMinor($a, $scale) <=> self::toMinor($b, $scale);
    }

    private static function scaleOf(string $amount): int
    {
        $dot = strpos($amount, '.');

        return $dot === false ? 0 : strlen($amount) - $dot - 1;
    }
}
