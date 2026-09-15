<?php

/**
 * Smoke-пример SDK: читает аккаунт тем ключом, что лежит в VDSOK_API_KEY.
 * Только чтение — ничего не заказывает и не тратит деньги.
 *
 *   composer install
 *   VDSOK_API_KEY=vk_test_... php examples/quickstart.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Vdsok\Sdk\Client;
use Vdsok\Sdk\Exception\ApiError;
use Vdsok\Sdk\Exception\VdsokException;

$apiKey = getenv('VDSOK_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "Set VDSOK_API_KEY first (create a key at https://vdsok.guru/my/api)\n");
    exit(2);
}

$vdsok = new Client($apiKey, [
    'baseUrl' => getenv('VDSOK_BASE_URL') ?: Client::DEFAULT_BASE_URL,
    'appInfo' => 'vdsok-sdk-php-example/1.0',
]);

try {
    $health = $vdsok->health();
    printf("API: %s (v%s)\n", $health['status'], $health['version']);

    $me = $vdsok->me();
    printf(
        "Key #%s, mode %s, scopes: %s\n",
        $me->get('key.id'),
        $me->get('key.mode'),
        implode(', ', $me['scopes'] ?: ['—']),
    );
    if (!$me['livemode']) {
        echo "Sandbox key: writes would be simulated.\n";
    }
    printf("Request id: %s, rate limit left: %s\n", (string) $me->requestId, (string) ($me->rateLimit?->remaining ?? '?'));

    $balance = $vdsok->balance->get();
    printf(
        "Balance: %s %s (next 7 days: %s, low: %s)\n",
        $balance['balance'],
        $balance['currency'],
        $balance['upcoming_7d'],
        $balance['low_balance'] ? 'yes' : 'no',
    );

    echo "\nServers:\n";
    $any = false;
    foreach ($vdsok->servers->iterate(['limit' => 20])->take(20) as $server) {
        $any = true;
        printf(
            "  #%-12s %-20s %-15s %-10s due %s\n",
            $server['id'],
            $server['name'],
            (string) ($server['primary_ip'] ?? '—'),
            $server['status'],
            (string) ($server['billing']['next_due_at'] ?? '—'),
        );
    }
    if (!$any) {
        echo "  (none)\n";
    }

    echo "\nCheapest tariffs:\n";
    $tariffs = $vdsok->catalog->tariffs()->items();
    usort($tariffs, static fn (array $a, array $b) => \Vdsok\Sdk\Money::compare($a['price_monthly'], $b['price_monthly']));
    foreach (array_slice($tariffs, 0, 5) as $tariff) {
        printf(
            "  %-12s %s %s/mo  %d vCPU / %d MB / %d GB  %s%s\n",
            $tariff['name'],
            $tariff['price_monthly'],
            $tariff['currency'],
            $tariff['resources']['cpu_cores'],
            $tariff['resources']['ram_mb'],
            $tariff['resources']['disk_gb'],
            $tariff['location']['code'],
            $tariff['in_stock'] ? '' : ' (out of stock)',
        );
    }
} catch (ApiError $e) {
    fwrite(STDERR, sprintf("API error %d %s: %s (request %s)\n", $e->status, $e->errorCode, $e->getMessage(), (string) $e->requestId));
    exit(1);
} catch (VdsokException $e) {
    fwrite(STDERR, 'SDK error: ' . $e->getMessage() . "\n");
    exit(1);
}
