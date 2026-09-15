<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Состояние лимита из заголовков `X-RateLimit-*`. Ключ имеет два ведра
 * (обычное 120/мин и «дорогое» 20/мин); заголовки описывают то ведро,
 * в которое попал конкретный вызов.
 */
final class RateLimitInfo
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $reset,
    ) {
    }

    /** @param array<string, string> $headers Имена заголовков в нижнем регистре */
    public static function fromHeaders(array $headers): ?self
    {
        $limit = self::intOrNull($headers['x-ratelimit-limit'] ?? null);
        $remaining = self::intOrNull($headers['x-ratelimit-remaining'] ?? null);
        $reset = self::intOrNull($headers['x-ratelimit-reset'] ?? null);
        if ($limit === null && $remaining === null && $reset === null) {
            return null;
        }

        return new self($limit, $remaining, $reset);
    }

    /** Момент сброса окна как дата (UTC) или null, если сервер его не прислал. */
    public function resetAt(): ?\DateTimeImmutable
    {
        if ($this->reset === null) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $this->reset))->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Секунд до сброса окна (не меньше 0). */
    public function secondsUntilReset(?int $now = null): ?int
    {
        if ($this->reset === null) {
            return null;
        }

        return max(0, $this->reset - ($now ?? time()));
    }

    private static function intOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }
}
