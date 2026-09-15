<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Resources;

use Vdsok\Sdk\Http\Transport;
use Vdsok\Sdk\Page;
use Vdsok\Sdk\Paginator;

/**
 * Общее для групп ресурсов: транспорт, проверка идентификаторов в пути
 * и сборка ленивого пагинатора.
 */
abstract class AbstractResource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Идентификатор в пути обязан быть положительным целым — иначе
     * `/servers/0` или `/servers/abc` уйдут на сервер и вернутся 404/400
     * без подсказки, что ошибка на стороне вызывающего.
     */
    protected static function id(int|string $id, string $name = 'id'): string
    {
        if (is_string($id)) {
            if (preg_match('/^\d+$/', $id) !== 1) {
                throw new \InvalidArgumentException("{$name} must be a positive integer, got \"{$id}\"");
            }
            $id = (int) $id;
        }
        if ($id < 1) {
            throw new \InvalidArgumentException("{$name} must be a positive integer, got {$id}");
        }

        return (string) $id;
    }

    /** Ленивый обход списка по курсору с сохранением остальных фильтров. */
    protected function paginate(string $path, array $query = []): Paginator
    {
        return new Paginator(function (?string $cursor) use ($path, $query): Page {
            return $this->transport->page('GET', $path, ['cursor' => $cursor] + $query);
        });
    }

    /** Опции запроса для идемпотентной операции: свой ключ или автогенерация. */
    protected static function idempotent(?string $idempotencyKey): array
    {
        return $idempotencyKey === null
            ? [Transport::OPT_IDEMPOTENT => true]
            : [Transport::OPT_IDEMPOTENCY_KEY => $idempotencyKey];
    }
}
