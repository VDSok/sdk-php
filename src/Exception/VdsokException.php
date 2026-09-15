<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Общий предок всех исключений SDK: одним `catch (VdsokException $e)`
 * можно поймать и ошибку API, и сетевой сбой, и неверную подпись вебхука.
 */
class VdsokException extends \RuntimeException
{
}
