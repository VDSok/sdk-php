<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Exception;

/**
 * Ожидание терминального состояния (например, `Orders::wait()` после
 * `202 provisioning`) исчерпало отведённое время. Заказ при этом жив:
 * его можно продолжить опрашивать по `invoiceId`.
 */
class TimeoutError extends VdsokException
{
    public function __construct(string $message, public readonly mixed $lastState = null)
    {
        parent::__construct($message);
    }
}
