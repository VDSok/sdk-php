<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Неверная настройка клиента: пустой ключ, нет ни одного PSR-18 клиента,
 * ключ идемпотентности вне 16..128 символов и т. п. Бросается до отправки
 * запроса, поэтому ловить её в ретраях не нужно.
 */
class ConfigurationError extends VdsokException
{
}
