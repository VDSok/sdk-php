<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

/**
 * Успешный JSON-ответ: декодированное тело плюс метаданные конверта.
 *
 * Тело отдаётся как есть (`$res['id']`, `$res->get('billing.next_due_at')`),
 * без классов-моделей на каждый объект: API растёт только аддитивно, и
 * новые поля должны быть доступны без обновления SDK. Даты по запросу
 * превращаются в `DateTimeImmutable` через `dateTime()`, деньги остаются
 * строками (см. `Money`).
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class ApiResponse implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /** Идентификатор запроса, выданный сервером (`X-Request-ID`), для обращений в поддержку. */
    public readonly ?string $requestId;

    /** Состояние лимита ведра, в которое попал вызов. */
    public readonly ?RateLimitInfo $rateLimit;

    /** true, если ответ дан тестовому ключу (`X-Sandbox: true`). */
    public readonly bool $sandbox;

    /**
     * @param array<string, mixed> $data           Декодированное тело ({} при 204)
     * @param array<string, string> $headers       Заголовки ответа, имена в нижнем регистре
     * @param string|null $idempotencyKey          Ключ, с которым ушёл запрос (только у идемпотентных операций)
     * @param string|null $clientRequestId         `X-Request-ID`, который отправил SDK
     */
    public function __construct(
        public readonly array $data,
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $clientRequestId = null,
    ) {
        $this->requestId = $headers['x-request-id'] ?? null;
        $this->rateLimit = RateLimitInfo::fromHeaders($headers);
        $this->sandbox = ($headers['x-sandbox'] ?? '') === 'true';
    }

    /**
     * Значение по пути через точку: `get('billing.next_due_at')`.
     * Отсутствующий ключ → `$default`, а не notice.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $current = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function has(string $path): bool
    {
        $missing = new \stdClass();

        return $this->get($path, $missing) !== $missing;
    }

    /** Поле-таймстамп как `DateTimeImmutable` (UTC); null, если поле null или отсутствует. */
    public function dateTime(string $path): ?\DateTimeImmutable
    {
        $value = $this->get($path);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Field {$path} is not a timestamp string");
        }

        return Timestamp::parse($value);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** 202 означает «принято, ещё выполняется» (заказ сервера, команда панели, трансфер домена). */
    public function isAccepted(): bool
    {
        return $this->statusCode === 202;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_array($this->data) && array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('ApiResponse is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('ApiResponse is immutable');
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
