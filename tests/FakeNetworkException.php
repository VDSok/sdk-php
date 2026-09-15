<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** Сетевой сбой PSR-18 для мок-клиента: именно такие SDK повторяет. */
final class FakeNetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request, string $message = 'connection reset')
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
