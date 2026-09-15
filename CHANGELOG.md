# Changelog

All notable changes to `vdsok/sdk` are documented here. The SDK follows
[Semantic Versioning](https://semver.org): 1.x tracks API v1, breaking API
changes go to `/v2` and a new SDK major.

## 1.0.0 (2026-09-15)

### Added

- First release against the VDSok Client API v1 (`docs/openapi/vdsok-client-api-v1.yaml`).
- `Vdsok\Sdk\Client` with resource groups `account`, `balance`, `invoices`,
  `catalog`, `servers` (with `actions`, `ips`, `orders`), `domains`, `sshKeys`,
  `keys`, `webhooks`, plus `me()`, `health()`, `openapi()` and a generic
  `request()` escape hatch.
- PSR-18 / PSR-17 transport: Guzzle is used when installed, any other client
  via `php-http/discovery`, or inject your own.
- `Idempotency-Key` generated automatically for money-moving operations and
  exposed on the result and on errors so a retry can reuse it.
- Retries with `Retry-After` support for `GET` and idempotent mutations
  (429, 502, 503, 504, `409 idempotency_in_progress`, network failures),
  exponential backoff with jitter, `maxRetryDelay` cap.
- `ApiError` with `status`, `errorCode` (the API's string code; `code` is
  taken by `\Exception` and `getCode()` returns the HTTP status),
  `message`, `requestId`, `details`,
  `rateLimit`, `retryAfter`, `idempotencyKey`; `TransportError` for network
  failures; `InvalidResponseError`, `ConfigurationError`, `TimeoutError`.
- `RateLimitInfo`, `X-Request-ID` and `X-Sandbox` exposed on every response.
- Cursor pagination (`Page`, `iterate()` generators, `take()`).
- `Orders::wait()` polling helper for `202 provisioning`.
- `Webhooks::verify()`, `isValid()` and `constructEvent()` with constant-time
  HMAC comparison and a 300 s timestamp tolerance; `WebhookEvent` envelope.
- `Timestamp::parse()` for RFC 3339 → `DateTimeImmutable` (UTC); `Money`
  helpers for string amounts (`toMinor`, `fromMinor`, `compare`).
