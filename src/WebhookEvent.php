<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

use Vdsok\Sdk\Exception\InvalidWebhookPayloadError;

/**
 * Разобранный конверт вебхука (`WebhookEvent` в спецификации).
 * `object` — снимок объекта в той же форме, что отдаёт REST API;
 * `previous` — изменившиеся поля (например `{"status": "active"}` у
 * `server.suspended`) или null.
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $raw Весь конверт как пришёл, для полей, которых SDK ещё не знает */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly \DateTimeImmutable $createdAt,
        public readonly bool $livemode,
        public readonly int $accountId,
        public readonly string $apiVersion,
        public readonly array $object,
        public readonly ?array $previous,
        public readonly ?string $resource,
        public readonly array $raw,
    ) {
    }

    /** @throws InvalidWebhookPayloadError */
    public static function fromArray(array $payload): self
    {
        foreach (['id', 'type', 'created_at', 'account_id', 'data'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new InvalidWebhookPayloadError("Webhook payload is missing `{$key}`");
            }
        }
        if (!is_string($payload['id']) || !is_string($payload['type']) || !is_string($payload['created_at'])) {
            throw new InvalidWebhookPayloadError('Webhook payload has wrong types for id/type/created_at');
        }
        $data = $payload['data'];
        if (!is_array($data) || !isset($data['object']) || !is_array($data['object'])) {
            throw new InvalidWebhookPayloadError('Webhook payload is missing `data.object`');
        }
        try {
            $createdAt = Timestamp::parse($payload['created_at']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidWebhookPayloadError('Webhook payload has a malformed `created_at`', 0, $e);
        }
        $previous = $data['previous'] ?? null;

        return new self(
            id: $payload['id'],
            type: $payload['type'],
            createdAt: $createdAt,
            livemode: (bool) ($payload['livemode'] ?? true),
            accountId: (int) $payload['account_id'],
            apiVersion: (string) ($payload['api_version'] ?? '1'),
            object: $data['object'],
            previous: is_array($previous) ? $previous : null,
            resource: isset($payload['resource']) && is_string($payload['resource']) ? $payload['resource'] : null,
            raw: $payload,
        );
    }

    /** @throws InvalidWebhookPayloadError */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidWebhookPayloadError('Webhook body is not valid JSON', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new InvalidWebhookPayloadError('Webhook body is not a JSON object');
        }

        return self::fromArray($decoded);
    }

    /** Первая часть типа: `server`, `invoice`, `balance`, `domain`, `key`, `ping`. */
    public function objectKind(): string
    {
        $dot = strpos($this->type, '.');

        return $dot === false ? $this->type : substr($this->type, 0, $dot);
    }

    public function isPing(): bool
    {
        return $this->type === 'ping';
    }
}
