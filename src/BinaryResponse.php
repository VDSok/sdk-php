<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Не-JSON ответ (сейчас только PDF счёта). Тело хранится строкой:
 * счета маленькие, стримить их некуда.
 */
final class BinaryResponse
{
    public readonly ?string $requestId;
    public readonly ?RateLimitInfo $rateLimit;
    public readonly bool $sandbox;
    public readonly ?string $contentType;

    /** @param array<string, string> $headers Имена заголовков в нижнем регистре */
    public function __construct(
        public readonly string $body,
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly ?string $clientRequestId = null,
    ) {
        $this->requestId = $headers['x-request-id'] ?? null;
        $this->rateLimit = RateLimitInfo::fromHeaders($headers);
        $this->sandbox = ($headers['x-sandbox'] ?? '') === 'true';
        $this->contentType = $headers['content-type'] ?? null;
    }

    /** Имя файла из `Content-Disposition`, например `invoice-10231.pdf`. */
    public function filename(): ?string
    {
        $disposition = $this->headers['content-disposition'] ?? '';
        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $m) === 1) {
            return rawurldecode(trim($m[1]));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    public function size(): int
    {
        return strlen($this->body);
    }

    /** Записать тело в файл; возвращает число записанных байт. */
    public function saveTo(string $path): int
    {
        $written = file_put_contents($path, $this->body);
        if ($written === false) {
            throw new \RuntimeException("Cannot write to {$path}");
        }

        return $written;
    }
}
