<?php

/**
 * Приёмник вебхуков VDSok на голом PHP.
 *
 * Важное: подпись считается по СЫРЫМ байтам тела. Никогда не проверяйте
 * её по результату json_encode(json_decode($body)) — пробелы и порядок
 * ключей изменятся, и подпись не сойдётся.
 *
 *   VDSOK_WEBHOOK_SECRET=whsec_... php -S 0.0.0.0:8080 examples/webhook-receiver.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Vdsok\Sdk\Exception\InvalidWebhookPayloadError;
use Vdsok\Sdk\Exception\WebhookSignatureError;
use Vdsok\Sdk\Webhooks;

$secret = getenv('VDSOK_WEBHOOK_SECRET') ?: '';
if ($secret === '') {
    http_response_code(500);
    exit("VDSOK_WEBHOOK_SECRET is not set\n");
}

$rawBody = (string) file_get_contents('php://input');

try {
    $event = Webhooks::constructEvent($secret, Webhooks::headersFromGlobals(), $rawBody);
} catch (WebhookSignatureError | InvalidWebhookPayloadError $e) {
    // 400 — VDSok не будет повторять заведомо непринятое событие бесконечно,
    // но запись останется в журнале доставок для разбора.
    http_response_code(400);
    error_log('VDSok webhook rejected: ' . $e->getMessage());
    exit;
}

// Повторы присылают те же байты и тот же id — дедуплицируйте по нему.
if (alreadyProcessed($event->id)) {
    http_response_code(200);
    exit;
}

switch ($event->type) {
    case 'server.created':
        error_log("Server {$event->object['id']} created: {$event->object['primary_ip']}");
        break;
    case 'server.suspended':
        error_log("Server {$event->object['id']} suspended (was {$event->previous['status']})");
        break;
    case 'invoice.paid':
        error_log("Invoice {$event->object['id']} paid: {$event->object['amount']} {$event->object['currency']}");
        break;
    case 'balance.low':
        error_log("Balance low: {$event->object['balance']} {$event->object['currency']}");
        break;
    case 'domain.expiring':
        error_log("Domain {$event->object['name']} expires {$event->object['expires_at']}");
        break;
    case 'ping':
        error_log('Ping from subscription ' . $event->object['subscription_id']);
        break;
    default:
        // Новые типы событий добавляются аддитивно — не падайте на них.
        error_log("Unhandled VDSok event {$event->type} ({$event->id})");
}

markProcessed($event->id);
http_response_code(200);

/** Замените на своё хранилище (таблица, Redis) с TTL хотя бы 24 часа. */
function alreadyProcessed(string $eventId): bool
{
    return file_exists(sys_get_temp_dir() . '/vdsok-' . preg_replace('/[^A-Za-z0-9_]/', '', $eventId));
}

function markProcessed(string $eventId): void
{
    touch(sys_get_temp_dir() . '/vdsok-' . preg_replace('/[^A-Za-z0-9_]/', '', $eventId));
}
