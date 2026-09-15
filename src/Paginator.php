<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Ленивый обход курсорного списка: `foreach ($client->servers->iterate() as $s)`.
 * Страницы запрашиваются по мере надобности, поэтому `break` в цикле
 * не тянет лишних запросов. Один экземпляр можно обойти несколько раз —
 * каждый обход начинается с первой страницы.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Paginator implements \IteratorAggregate
{
    /** @param \Closure(?string $cursor): Page $fetch */
    public function __construct(
        private readonly \Closure $fetch,
        private readonly ?int $maxItems = null,
    ) {
    }

    /** @return \Generator<int, Page> */
    public function pages(): \Generator
    {
        $cursor = null;
        $seen = [];
        do {
            $page = ($this->fetch)($cursor);
            yield $page;
            $next = $page->nextCursor;
            // Защита от зацикливания, если сервер вернёт уже виденный курсор.
            if ($next !== null && isset($seen[$next])) {
                return;
            }
            if ($next !== null) {
                $seen[$next] = true;
            }
            $cursor = $next;
        } while ($cursor !== null);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function getIterator(): \Generator
    {
        $emitted = 0;
        foreach ($this->pages() as $page) {
            foreach ($page->items() as $item) {
                if ($this->maxItems !== null && $emitted >= $this->maxItems) {
                    return;
                }
                $emitted++;
                yield $item;
            }
            if ($this->maxItems !== null && $emitted >= $this->maxItems) {
                return;
            }
        }
    }

    /** Ограничить обход первыми N элементами (без изменения `limit` на сервере). */
    public function take(int $maxItems): self
    {
        return new self($this->fetch, $maxItems);
    }

    /** Собрать всё в массив. Осторожно на больших списках. */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }
}
