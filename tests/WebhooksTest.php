<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Vdsok\Sdk\Exception\InvalidWebhookPayloadError;
use Vdsok\Sdk\Exception\WebhookSignatureError;
use Vdsok\Sdk\WebhookEvent;
use Vdsok\Sdk\Webhooks;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1_789_000_000;

    private const BODY = '{"id":"evt_01J7ZK3Q9X4R","type":"server.suspended","created_at":"2026-09-15T10:00:00Z",'
        . '"livemode":true,"account_id":57,"api_version":"1",'
        . '"data":{"object":{"id":2001,"name":"web-01","status":"suspended"},"previous":{"status":"active"}},'
        . '"resource":"/api/v1/servers/2001"}';

    private function headers(int $timestamp = self::NOW, ?string $signature = null, string $body = self::BODY): array
    {
        return [
            'X-Webhook-Signature' => $signature ?? Webhooks::signatureHeader(self::SECRET, $timestamp, $body),
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Id' => 'evt_01J7ZK3Q9X4R',
            'X-Webhook-Event' => 'server.suspended',
            'Content-Type' => 'application/json',
        ];
    }

    public function testSignMatchesTheSpecFormula(): void
    {
        $expected = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        self::assertSame($expected, Webhooks::sign(self::SECRET, self::NOW, self::BODY));
        self::assertSame('v1=' . $expected, Webhooks::signatureHeader(self::SECRET, self::NOW, self::BODY));
    }

    public function testValidSignaturePasses(): void
    {
        Webhooks::verify(self::SECRET, $this->headers(), self::BODY, 300, self::NOW + 10);
        self::assertTrue(Webhooks::isValid(self::SECRET, $this->headers(), self::BODY, 300, self::NOW - 299));
    }

    public function testHeaderNamesAreCaseInsensitiveAndPsr7ArraysAccepted(): void
    {
        $headers = [
            'x-webhook-signature' => [Webhooks::signatureHeader(self::SECRET, self::NOW, self::BODY)],
            'X-WEBHOOK-TIMESTAMP' => [(string) self::NOW],
        ];
        self::assertTrue(Webhooks::isValid(self::SECRET, $headers, self::BODY, 300, self::NOW));
    }

    public function testWrongSecretFails(): void
    {
        $this->expectException(WebhookSignatureError::class);
        Webhooks::verify('whsec_other', $this->headers(), self::BODY, 300, self::NOW);
    }

    public function testTamperedBodyFails(): void
    {
        $tampered = str_replace('"suspended"', '"active"', self::BODY);
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(), $tampered, 300, self::NOW));
    }

    public function testReserializedBodyFails(): void
    {
        // Пробел после двоеточия — типичный результат json_encode(json_decode(...)) с флагами.
        $reserialized = json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(), (string) $reserialized, 300, self::NOW));
    }

    public function testMissingSignatureHeaderFails(): void
    {
        $headers = $this->headers();
        unset($headers['X-Webhook-Signature']);
        $this->expectException(WebhookSignatureError::class);
        $this->expectExceptionMessage('Missing X-Webhook-Signature');
        Webhooks::verify(self::SECRET, $headers, self::BODY, 300, self::NOW);
    }

    public function testMissingTimestampFails(): void
    {
        $headers = $this->headers();
        unset($headers['X-Webhook-Timestamp']);
        $this->expectException(WebhookSignatureError::class);
        Webhooks::verify(self::SECRET, $headers, self::BODY, 300, self::NOW);
    }

    public function testMalformedTimestampFails(): void
    {
        $headers = $this->headers();
        $headers['X-Webhook-Timestamp'] = '2026-09-15T10:00:00Z';
        $this->expectException(WebhookSignatureError::class);
        Webhooks::verify(self::SECRET, $headers, self::BODY, 300, self::NOW);
    }

    public function testStaleTimestampFails(): void
    {
        $this->expectException(WebhookSignatureError::class);
        $this->expectExceptionMessage('tolerance');
        Webhooks::verify(self::SECRET, $this->headers(), self::BODY, 300, self::NOW + 301);
    }

    public function testFutureTimestampBeyondToleranceFails(): void
    {
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(), self::BODY, 300, self::NOW - 301));
    }

    public function testCustomToleranceAndDisabledCheck(): void
    {
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(), self::BODY, 60, self::NOW + 61));
        self::assertTrue(Webhooks::isValid(self::SECRET, $this->headers(), self::BODY, 600, self::NOW + 599));
        self::assertTrue(Webhooks::isValid(self::SECRET, $this->headers(), self::BODY, -1, self::NOW + 999_999));
    }

    public function testDefaultsToCurrentTimeAndDefaultTolerance(): void
    {
        $ts = time();
        self::assertTrue(Webhooks::isValid(self::SECRET, $this->headers($ts), self::BODY));
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers($ts - 1000), self::BODY));
        self::assertSame(300, Webhooks::DEFAULT_TOLERANCE);
    }

    public function testMultipleSignaturesOneValidPasses(): void
    {
        $good = Webhooks::signatureHeader(self::SECRET, self::NOW, self::BODY);
        $bad = 'v1=' . str_repeat('0', 64);
        self::assertTrue(Webhooks::isValid(self::SECRET, $this->headers(signature: "{$bad}, {$good}"), self::BODY, 300, self::NOW));
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(signature: "{$bad},{$bad}"), self::BODY, 300, self::NOW));
    }

    public function testUnknownSchemeIsIgnored(): void
    {
        $hex = Webhooks::sign(self::SECRET, self::NOW, self::BODY);
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(signature: 'v0=' . $hex), self::BODY, 300, self::NOW));
        self::assertFalse(Webhooks::isValid(self::SECRET, $this->headers(signature: $hex), self::BODY, 300, self::NOW));
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(WebhookSignatureError::class);
        Webhooks::verify('', $this->headers(), self::BODY, 300, self::NOW);
    }

    public function testConstructEventReturnsParsedEvent(): void
    {
        $event = Webhooks::constructEvent(self::SECRET, $this->headers(), self::BODY, 300, self::NOW);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('evt_01J7ZK3Q9X4R', $event->id);
        self::assertSame('server.suspended', $event->type);
        self::assertSame('server', $event->objectKind());
        self::assertFalse($event->isPing());
        self::assertSame('2026-09-15T10:00:00+00:00', $event->createdAt->format(DATE_ATOM));
        self::assertSame('UTC', $event->createdAt->getTimezone()->getName());
        self::assertTrue($event->livemode);
        self::assertSame(57, $event->accountId);
        self::assertSame('1', $event->apiVersion);
        self::assertSame(['id' => 2001, 'name' => 'web-01', 'status' => 'suspended'], $event->object);
        self::assertSame(['status' => 'active'], $event->previous);
        self::assertSame('/api/v1/servers/2001', $event->resource);
        self::assertSame('server.suspended', $event->raw['type']);
    }

    public function testConstructEventRejectsBadSignatureBeforeParsing(): void
    {
        $this->expectException(WebhookSignatureError::class);
        Webhooks::constructEvent('whsec_wrong', $this->headers(), self::BODY, 300, self::NOW);
    }

    public function testPingEventWithNullResource(): void
    {
        $body = '{"id":"evt_ping1","type":"ping","created_at":"2026-09-15T10:00:00.250Z","livemode":true,"account_id":57,'
            . '"api_version":"1","data":{"object":{"subscription_id":5,"message":"pong"},"previous":null},"resource":null}';
        $event = Webhooks::constructEvent(self::SECRET, $this->headers(body: $body), $body, 300, self::NOW);

        self::assertTrue($event->isPing());
        self::assertSame('ping', $event->objectKind());
        self::assertNull($event->resource);
        self::assertNull($event->previous);
        self::assertSame('pong', $event->object['message']);
        self::assertSame('250000', $event->createdAt->format('u'));
    }

    public function testMalformedPayloadAfterValidSignature(): void
    {
        $body = '{"id":"evt_x","type":"server.created"}';
        $this->expectException(InvalidWebhookPayloadError::class);
        Webhooks::constructEvent(self::SECRET, $this->headers(body: $body), $body, 300, self::NOW);
    }

    public function testNonJsonPayloadAfterValidSignature(): void
    {
        $body = 'not json';
        $this->expectException(InvalidWebhookPayloadError::class);
        Webhooks::constructEvent(self::SECRET, $this->headers(body: $body), $body, 300, self::NOW);
    }

    public function testEventFromArrayValidatesShape(): void
    {
        $this->expectException(InvalidWebhookPayloadError::class);
        WebhookEvent::fromArray(['id' => 'evt_1', 'type' => 'x', 'created_at' => 'yesterday', 'account_id' => 1, 'data' => ['object' => []]]);
    }

    public function testHeadersFromGlobals(): void
    {
        $server = [
            'HTTP_X_WEBHOOK_SIGNATURE' => 'v1=abc',
            'HTTP_X_WEBHOOK_TIMESTAMP' => '123',
            'HTTP_HOST' => 'hooks.example.com',
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ];
        self::assertSame(
            ['x-webhook-signature' => 'v1=abc', 'x-webhook-timestamp' => '123', 'host' => 'hooks.example.com'],
            Webhooks::headersFromGlobals($server),
        );
    }
}
