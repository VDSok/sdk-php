<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Ответ-список `{"data": [...], "next_cursor": "..."|null}`.
 * Списки без пагинации (тарифы, SSH-ключи, IP сервера) приходят в той же
 * форме без `next_cursor` — для них `nextCursor` всегда null.
 */
final class Page extends ApiResponse
{
    /** Курсор следующей страницы; передаётся как есть в `?cursor=`. */
    public readonly ?string $nextCursor;

    public function __construct(
        array $data,
        int $statusCode,
        array $headers = [],
        ?string $idempotencyKey = null,
        ?string $clientRequestId = null,
    ) {
        parent::__construct($data, $statusCode, $headers, $idempotencyKey, $clientRequestId);
        $cursor = $data['next_cursor'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        $items = $this->data['data'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    /** Первый элемент или null — удобно для `list(['ip' => ...])`. */
    public function first(): ?array
    {
        $items = $this->items();

        return $items[0] ?? null;
    }

    /** Число элементов на этой странице (не во всём списке). */
    public function count(): int
    {
        return count($this->items());
    }

    /** Итерация по элементам страницы, а не по ключам конверта. */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items());
    }
}
