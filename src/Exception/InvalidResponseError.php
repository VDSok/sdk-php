<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * API ответил 2xx, но тело не разобралось как JSON-объект. Такое бывает
 * только при поломке между клиентом и API (прокси, captive portal), и это
 * не повод для ретрая — пусть вызывающий увидит настоящую причину.
 */
class InvalidResponseError extends VdsokException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $requestId = null,
        public readonly string $bodyExcerpt = '',
    ) {
        parent::__construct($message, $status);
    }
}
