# VDSok SDK for PHP

[![Packagist](https://img.shields.io/packagist/v/vdsok/sdk.svg)](https://packagist.org/packages/vdsok/sdk)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![Tests](https://github.com/VDSok/sdk-php/actions/workflows/tests.yml/badge.svg)](https://github.com/VDSok/sdk-php/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Official PHP client for the [VDSok Client API v1](https://vdsok.guru/developers):
balance and invoices, the VDS catalog, servers (order, renew, power,
reinstall, IPs, PTR, delete with refund), domains, API keys and webhooks.

* PHP >= 8.1, PSR-18 / PSR-17 — bring your own HTTP client or let it pick Guzzle.
* Money as strings, timestamps as `DateTimeImmutable`, no floats anywhere.
* Automatic `Idempotency-Key` on every money-moving call, retries with
  `Retry-After` for reads and idempotent writes.
* Cursor pagination as generators, webhook signature verification.

Docs: <https://vdsok.guru/developers> · Source: <https://github.com/VDSok/sdk-php> ·
Issues: <https://github.com/VDSok/sdk-php/issues>

## Install

```bash
composer require vdsok/sdk guzzlehttp/guzzle
```

Guzzle is the recommended transport and is picked up automatically. Any
other PSR-18 client works too — either install `php-http/discovery` next
to it, or pass the client and PSR-17 factories explicitly (see
[Configuration](#configuration)).

## Quick start

```php
use Vdsok\Sdk\Client;

$vdsok = new Client(getenv('VDSOK_API_KEY')); // vk_live_… or vk_test_…

$me = $vdsok->me();
echo $me['key']['mode'], ' key #', $me['key']['id'], ' scopes: ', implode(', ', $me['scopes']), "\n";
echo $me['livemode'] ? "live\n" : "sandbox\n";   // false for vk_test_… keys

$balance = $vdsok->balance->get();
echo $balance['balance'], ' ', $balance['currency'], "\n";   // "42.15 USD" — strings, never floats

foreach ($vdsok->servers->iterate(['status' => 'active']) as $server) {
    printf("#%d %s %s due %s\n",
        $server['id'], $server['name'], $server['primary_ip'],
        $server['billing']['next_due_at'] ?? '—');
}
```

Create a key in the cabinet at `/my/api`. A `vk_test_` key reads real data
and **simulates** every write (no money moves, no VM is created), so you can
develop against production safely; every response then carries
`X-Sandbox: true` (`$result->sandbox`).

## Ordering a server

```php
use Vdsok\Sdk\Exception\ApiError;

$quote = $vdsok->catalog->quote(['tariff_id' => 12, 'months' => 3]);
if (!$quote['balance_sufficient']) {
    echo "Top up {$quote['shortfall']} {$quote['currency']} first\n";
}

try {
    $result = $vdsok->servers->create([
        'tariff_id'   => 12,
        'os'          => 'ubuntu-24.04',
        'name'        => 'web-01',
        'months'      => 3,
        'ssh_key_ids' => [3],
    ]);

    if ($result->isAccepted()) {
        // 202: charged, the panel is still creating the VM — poll the order.
        $order = $vdsok->servers->orders->wait($result['invoice_id']);   // active | cancelled (refunded)
        $serverId = $order['server_id'];
    } else {
        // 201: server is up, root password is shown exactly once.
        $serverId = $result['server']['id'];
        $rootPassword = $result['root_password'];
    }
} catch (ApiError $e) {
    if ($e->isInsufficientFunds()) {
        echo "Need {$e->details['shortfall']} {$e->details['currency']} more\n";
    }
    // $e->idempotencyKey lets you retry the very same order safely:
    // $vdsok->servers->create($params, $e->idempotencyKey);
    throw $e;
}
```

## Idempotency and retries

Operations that move money or touch external systems (`servers->create`,
`servers->delete`, `servers->renew`, `servers->ips->add`, `invoices->pay`,
`balance->topup`, `domains->register|renew|transfer`) **require** an
`Idempotency-Key`. The SDK generates a UUID for you and exposes it on the
result (`$result->idempotencyKey`) and on the error (`$error->idempotencyKey`).
Pass your own key (16..128 chars, e.g. your order id) to make retries across
processes safe:

```php
$vdsok->servers->renew(2001, ['months' => 1], idempotencyKey: "renew-2001-2026-10");
```

Retries (`maxRetries`, default 2) apply to `GET` requests and to mutations
carrying an `Idempotency-Key`, on `429`, `502`, `503`, `504`,
`409 idempotency_in_progress` and network failures. The pause comes from
`Retry-After` when the server sends it, otherwise exponential backoff with
jitter; both are capped by `maxRetryDelay` (default 60 s). Power commands,
reinstall and other non-idempotent writes are never retried automatically.

## Results

Every call returns an `ApiResponse` (or a `Page` for lists) that behaves
like a read-only array and carries the response envelope:

```php
$server = $vdsok->servers->get(2001);

$server['name'];                          // array access
$server->get('billing.next_due_at');      // dot paths
$server->dateTime('created_at');          // DateTimeImmutable (UTC) or null
$server->toArray();                       // plain array
$server->requestId;                       // X-Request-ID — quote it to support
$server->rateLimit->remaining;            // X-RateLimit-* of the bucket you hit
$server->sandbox;                         // true for vk_test_ keys
$server->statusCode;                      // 200 / 201 / 202 …
```

Money fields are strings such as `"5.90"` or `"0.0083"`. `Vdsok\Sdk\Money`
helps when you need arithmetic without floats:

```php
use Vdsok\Sdk\Money;

Money::toMinor('5.90');          // 590
Money::fromMinor(2500);          // "25.00"
Money::compare('4.10', '5.90');  // -1
```

## Pagination

```php
$page = $vdsok->invoices->list(['status' => 'not_paid', 'limit' => 50]);
$page->items();       // this page
$page->nextCursor;    // pass back as ['cursor' => …] or use iterate()

foreach ($vdsok->invoices->iterate(['status' => 'not_paid']) as $invoice) {
    // pages are fetched lazily; `break` stops the requests
}

$last10 = $vdsok->balance->iterateTransactions()->take(10)->toArray();
```

## Errors

| Exception | When |
|-----------|------|
| `Vdsok\Sdk\Exception\ApiError` | The API answered with a status >= 400. `status`, `errorCode`, `getMessage()`, `requestId`, `details`, `rateLimit`, `retryAfter`, `idempotencyKey`. Helpers: `isRateLimited()`, `isInsufficientFunds()`, `isNotFound()`, `isForbidden()`, `isAuthError()`, `isRetryable()`, `fieldErrors()`. |
| `Vdsok\Sdk\Exception\TransportError` | No response at all (DNS, connection, timeout) after the retries. |
| `Vdsok\Sdk\Exception\InvalidResponseError` | 2xx with a body that is not JSON (a proxy in the way). |
| `Vdsok\Sdk\Exception\ConfigurationError` | Bad options, missing HTTP client, invalid idempotency key. |
| `Vdsok\Sdk\Exception\WebhookSignatureError` | Webhook signature or timestamp check failed. |
| `Vdsok\Sdk\Exception\TimeoutError` | `orders->wait()` ran out of time; `lastState` holds the last order. |

All of them extend `Vdsok\Sdk\Exception\VdsokException`. Unknown error
codes are tolerated — branch on `$e->status` when `$e->errorCode` is unfamiliar.

The API's string code lives on `errorCode`, not on `code`: `\Exception`
already owns `$code` (returned by `getCode()`, which here gives the HTTP
status), and PHP refuses to redeclare it.

```php
try {
    $vdsok->servers->delete(2001);
} catch (ApiError $e) {
    echo "{$e->status} {$e->errorCode}: {$e->getMessage()} (request {$e->requestId})\n";
}
```

## Resources

| Group | Methods |
|-------|---------|
| `$vdsok->me()`, `health()`, `openapi()` | calling key, liveness, spec |
| `account` | `get()` |
| `balance` | `get()`, `transactions()`, `iterateTransactions()`, `topupInfo()`, `topup()` |
| `invoices` | `list()`, `iterate()`, `get()`, `pdf()`, `pay()`, `paymentLink()` |
| `catalog` | `tariffs()`, `tariff()`, `osImages()`, `locations()`, `zones()`, `quote()` |
| `servers` | `list()`, `iterate()`, `findByIp()`, `create()`, `get()`, `update()`, `setAutoRenew()`, `delete()`, `status()`, `refundQuote()`, `renew()` |
| `servers->actions` | `power()`, `start()`, `stop()`, `restart()`, `reinstall()`, `resetPassword()` |
| `servers->ips` | `list()`, `quote()`, `add()`, `delete()`, `setPtr()` |
| `servers->orders` | `list()`, `iterate()`, `get()`, `wait()` |
| `domains` | `availability()`, `list()`, `iterate()`, `register()`, `get()`, `update()`, `renew()`, `setNameservers()`, `transfer()` |
| `sshKeys` | `list()`, `create()`, `delete()` |
| `keys` | `list()`, `get()`, `revoke()`, `revokeCurrent()` |
| `webhooks` | `list()`, `create()`, `events()`, `get()`, `update()`, `delete()`, `rotateSecret()`, `test()`, `deliveries()`, `iterateDeliveries()`, `delivery()`, `redeliver()` |

Anything not covered yet: `$vdsok->request('POST', '/new/endpoint', $query, $body, ['idempotent' => true])`.

## Webhooks

Verify the **raw** request body — never a re-serialized copy:

```php
use Vdsok\Sdk\Webhooks;
use Vdsok\Sdk\Exception\WebhookSignatureError;

$secret  = getenv('VDSOK_WEBHOOK_SECRET');           // whsec_… from webhooks->create()
$rawBody = file_get_contents('php://input');

try {
    $event = Webhooks::constructEvent($secret, Webhooks::headersFromGlobals(), $rawBody);
} catch (WebhookSignatureError $e) {
    http_response_code(400);
    exit;
}

// $event->id, ->type, ->createdAt, ->object, ->previous, ->resource
switch ($event->type) {
    case 'server.suspended':
        notify("Server {$event->object['name']} suspended (was {$event->previous['status']})");
        break;
    case 'invoice.paid':
        markPaid($event->object['id']);
        break;
}
http_response_code(200);
```

With a PSR-7 request: `Webhooks::constructEvent($secret, $request->getHeaders(), (string) $request->getBody())`.
Deliveries are retried with the same `id`, so deduplicate on `$event->id`
(also sent as `X-Webhook-Id`). `Webhooks::verify()` throws,
`Webhooks::isValid()` returns a bool; the default timestamp tolerance is
300 s. `Webhooks::signatureHeader($secret, $ts, $body)` lets you sign test
payloads for your own receiver.

## Configuration

```php
$vdsok = new Client('vk_live_…', [
    'baseUrl'       => 'https://vdsok.guru/api/v1',   // default
    'timeout'       => 30,                            // seconds, applied to the Guzzle client the SDK creates
    'maxRetries'    => 2,
    'maxRetryDelay' => 60,
    'appInfo'       => 'my-panel/2.1',                // appended to User-Agent: vdsok-sdk-php/1.0.0 my-panel/2.1
    // Bring your own transport (then `timeout` is yours to configure):
    'httpClient'     => $psr18Client,
    'requestFactory' => $psr17RequestFactory,
    'streamFactory'  => $psr17StreamFactory,
]);
```

Every request carries `Authorization: Bearer …`, `Accept: application/json`,
`User-Agent: vdsok-sdk-php/<version>` and a fresh `X-Request-ID` (UUID v4,
available as `$result->clientRequestId`). The key is never written to
logs, exceptions or `var_dump()` output.

## Development

```bash
composer install
composer test        # phpunit
composer lint        # php -l over src/, tests/, examples/, tools/
```

Both work on Windows as well — `composer lint` runs `tools/lint.php` and
needs nothing but PHP itself.

Tests run against a mock PSR-18 client (`php-http/mock-client`); no network
access is needed. `php -l` only catches parse errors, so `composer test` is
the gate that matters before a release.

## Versioning

SDK 1.x tracks API v1. The API only grows additively; breaking changes go to
`/v2` and a new SDK major. See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE) © VDSok
