<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Util;

/**
 * UUID v4 без внешней зависимости: нужен для `X-Request-ID` и
 * автоматических `Idempotency-Key`. Тащить ramsey/uuid ради 36 символов
 * не хочется — у SDK и так минимум зависимостей.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // версия 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // вариант RFC 4122
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function isV4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
