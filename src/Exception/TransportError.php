<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Сетевой сбой: DNS, соединение, таймаут, обрыв. Ответа от API не было,
 * поэтому нет ни статуса, ни кода. Для GET и запросов с Idempotency-Key
 * SDK уже попробовал повторить (`maxRetries`) прежде чем бросить это.
 *
 * Заголовки запроса (и ключ API в них) сюда намеренно не попадают.
 */
class TransportError extends VdsokException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $url,
        public readonly ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
