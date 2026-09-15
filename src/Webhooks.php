<?php

declare(strict_types=1);

namespace Vdsok\Sdk;

use Vdsok\Sdk\Exception\WebhookSignatureError;

/**
 * Проверка подписи входящих вебхуков VDSok.
 *
 * Заголовки доставки:
 *   X-Webhook-Signature: v1=<hex HMAC-SHA256(secret, "{ts}.{body}")>
 *   X-Webhook-Timestamp: <unix seconds>
 *   X-Webhook-Id:        evt_…   (одинаков у всех повторов)
 *   X-Webhook-Event:     server.created
 *
 * Подписываются СЫРЫЕ байты тела — никогда не пересериализуйте JSON
 * перед проверкой (`file_get_contents('php://input')`).
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE = 300;
    public const SIGNATURE_HEADER = 'x-webhook-signature';
    public const TIMESTAMP_HEADER = 'x-webhook-timestamp';
    public const ID_HEADER = 'x-webhook-id';
    public const EVENT_HEADER = 'x-webhook-event';

    /** HMAC-SHA256 в hex, как считает сервер. Полезно и для тестов своего приёмника. */
    public static function sign(string $secret, int|string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** Готовое значение заголовка `X-Webhook-Signature` для той же пары timestamp/body. */
    public static function signatureHeader(string $secret, int|string $timestamp, string $body): string
    {
        return 'v1=' . self::sign($secret, $timestamp, $body);
    }

    /**
     * Проверяет подпись и метку времени; при любой проблеме бросает
     * WebhookSignatureError. Тексты ошибок нарочно не уточняют, что именно
     * не сошлось, дальше «подпись/время/заголовки».
     *
     * @param array<string, string|string[]> $headers Заголовки запроса; регистр имён не важен,
     *                                                 значения — строка или массив (как у PSR-7 `getHeaders()`)
     * @param int|null $now                            Для тестов: «текущее» unix-время
     *
     * @throws WebhookSignatureError
     */
    public static function verify(
        string $secret,
        array $headers,
        string $rawBody,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        if ($secret === '') {
            throw new WebhookSignatureError('Webhook secret is empty');
        }
        $normalized = self::normalizeHeaders($headers);

        $signatureHeader = $normalized[self::SIGNATURE_HEADER] ?? null;
        $timestampHeader = $normalized[self::TIMESTAMP_HEADER] ?? null;
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new WebhookSignatureError('Missing X-Webhook-Signature header');
        }
        if ($timestampHeader === null || preg_match('/^\d{1,12}$/', trim($timestampHeader)) !== 1) {
            throw new WebhookSignatureError('Missing or malformed X-Webhook-Timestamp header');
        }
        $timestamp = (int) trim($timestampHeader);

        if ($tolerance >= 0) {
            $current = $now ?? time();
            if (abs($current - $timestamp) > $tolerance) {
                throw new WebhookSignatureError('Webhook timestamp is outside the allowed tolerance');
            }
        }

        $expected = self::sign($secret, $timestamp, $rawBody);
        $matched = false;
        // Заголовок может содержать несколько подписей через запятую
        // (например, в период ротации секрета); достаточно одной верной.
        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (!str_starts_with($part, 'v1=')) {
                continue;
            }
            $candidate = substr($part, 3);
            // hash_equals сравнивает за постоянное время только строки равной
            // длины; разная длина — заведомо не наша подпись.
            if (strlen($candidate) === strlen($expected) && hash_equals($expected, $candidate)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookSignatureError('Webhook signature does not match');
        }
    }

    /** То же, что `verify()`, но булево — для кода, где исключения неудобны. */
    public static function isValid(
        string $secret,
        array $headers,
        string $rawBody,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        try {
            self::verify($secret, $headers, $rawBody, $tolerance, $now);

            return true;
        } catch (WebhookSignatureError) {
            return false;
        }
    }

    /**
     * Проверяет подпись и возвращает разобранное событие.
     *
     * @throws WebhookSignatureError
     * @throws \Vdsok\Sdk\Exception\InvalidWebhookPayloadError
     */
    public static function constructEvent(
        string $secret,
        array $headers,
        string $rawBody,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): WebhookEvent {
        self::verify($secret, $headers, $rawBody, $tolerance, $now);

        return WebhookEvent::fromJson($rawBody);
    }

    /**
     * Заголовки текущего запроса из `$_SERVER` (`HTTP_X_WEBHOOK_*`) для
     * приёмников без PSR-7. Использование:
     *   Webhooks::constructEvent($secret, Webhooks::headersFromGlobals(), file_get_contents('php://input'))
     *
     * @return array<string, string>
     */
    public static function headersFromGlobals(?array $server = null): array
    {
        $server ??= $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_') || !is_string($value)) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, string|string[]> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            }
            if (!is_scalar($value)) {
                continue;
            }
            $out[strtolower($name)] = (string) $value;
        }

        return $out;
    }
}
