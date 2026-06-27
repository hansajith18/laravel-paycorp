# Changelog

All notable changes to `hansajith18/laravel-paycorp` will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-06-27

### Changed
- **Architecture:** `PaycenterPaymentService` no longer talks to the gateways directly — it now delegates all IPG communication (DTO building, client-ID resolution, gateway calls) to `PaycorpClient`, removing the duplicated request-building logic that previously existed in both classes. The service is now a pure orchestration layer (Payment lifecycle, idempotency, events, persistence) on top of the client facade.
- **Internal:** `PaycenterPaymentService` constructor now accepts `(PaycorpClient $client, GatewayAuditLogger $audit, ?PaycorpLogger $logger)`. All public methods and behaviour are unchanged; the service is resolved from the container as before via `app(PaycenterPaymentService::class)`.
- **Payment flows clarified:**
  - `initializePayment()` is the single entry point for **both** normal purchases and card tokenization (card-save). It always creates a `Payment` record and returns a hosted-page URL for the user to redirect to.
  - `completePayment()` is called for **both** flows after the user returns from the Paycorp hosted page. For card registration, it additionally saves the token to `saved_payments` and dispatches `VoidTokenizationCharge` to reverse the verification charge.
  - `chargeWithSavedPayment()` is the entry point for **Real-Time** charges against a previously saved card token. No hosted-page redirect is involved — the result is immediate and synchronous.
- `PaycorpClient::initPayment()` gained optional `transactionType`, `tokenize`, and `tokenReference` parameters (backward compatible) so it can serve every hosted-page scenario the service needs
- `PaymentPurposeEnum` now implements `PaymentPurposeContract` and has been updated to generic cases: `PURCHASE`, `SUBSCRIPTION`, `CARD_REGISTRATION`, `WALLET_TOPUP`, `REFUND`
- `PaycenterPaymentService::initializePayment()` and `chargeWithSavedPayment()` now accept any `\BackedEnum` instead of the concrete `PaymentPurposeEnum`, enabling custom purpose enums
- `generateClientRef()` derives the 4-character prefix from the enum value automatically — no hard-coded match required for custom enums
- `Payment` model uses `getCasts()` to read the purpose cast class from `config('paycorp.purpose_class')` at runtime
- `PaycorpHttpGateway::getClientId()` now validates the resolved config key before casting — throws `RuntimeException` with the exact env variable name when a client ID is not configured, instead of silently sending `0` to the gateway
- `PaycorpRefundGateway` constructor validates all required refund credentials (`refund_device_id`, `refund_iv_phrase`, `refund_aes_secret`) upfront; `login()` validates `refund_username` and `refund_password` individually — each error message names the specific env variable to set
- `initializePayment()` and `chargeWithSavedPayment()` now call `validatePayableType()` before the DB insert, throwing `ValidationException` when the payable class does not exist — prevents Eloquent's "Class not found" error at query time

### Added

- `GatewayAuditLogger` — dedicated collaborator that owns all PCI-safe audit logging to `payment_gateway_logs` (request/response persistence, payload sanitisation, card masking), extracted out of `PaycenterPaymentService`
- `PaycorpClient::realTimePayment()` — drive a tokenised real-time charge directly through the client without database involvement
- `PaycorpClient::isRefundConfigured()`, `getLastRequestPayload()`, `getLastRefundRequestPayload()` helpers
- `PaymentPurposeContract` interface — implement on any string-backed enum to use custom payment purposes
- `purpose_class` config key — swap in your own enum class; `Payment` model casts purpose through it
- `card_registration_purpose` config key — string value that identifies a card-registration payment and triggers the auto-void job (default `card_registration`)
- `payable_status_column` config key — configurable column name written on the payable model when payment status changes (default `payment_status`, env `PAYCORP_PAYABLE_STATUS_COLUMN`)

**Payment flows**
- Hosted Page flow: `PaycorpClient::initPayment()` and `completePayment()`
- Real-Time flow: charge tokenised cards via `PaycenterPaymentService::chargeWithSavedPayment()`
- Refund and Void via `PaycorpClient::refund()` / `void()` and `PaycenterPaymentService::voidPayment()`

**Security & signing**
- HMAC-SHA256 request signing on every outgoing API call
- TLS verification enforced (`verify: true`) on all HTTP connections
- AES-256-CBC encryption of refund credentials before transmission
- `toSafeArray()` on response DTOs — excludes cardholder name, raw expiry, comments
- No raw card numbers or CVVs are ever stored; only masked card data is persisted

**Database & audit**
- `payments` table — tracks status, masked card info, gateway references, polymorphic payable
- `payment_gateway_logs` table — full request/response audit trail per operation
- `saved_payments` table — tokenised card storage per user

**Service provider**
- Auto-discovery via Laravel package discovery
- `mergeConfigFrom` with `paycorp-config` publish tag
- `paycorp-migrations` publish tag
- Auto-registered `paycorp` daily log channel (no changes to `logging.php` needed)
- Short container alias `app('paycorp')` resolves to `PaycorpClient`

**Developer experience**
- `php artisan paycorp:publish` command with post-install guide
- `PaymentSucceeded` and `PaymentFailed` events
- `VoidTokenizationCharge` queued job with 5-attempt retry backoff
- `payable_status_classes` config key for automatic polymorphic payable status updates
- Sandbox mode with automatic amount normalisation for test environment constraints
- Idempotency guard in `completePayment` (DB lock prevents duplicate gateway calls)

**CI / Quality**
- GitHub Actions matrix: PHP 8.2 + 8.3 against Laravel 10, 11, 12, and 13
- Automatic GitHub Release creation on version tag push
- 46 unit tests covering client delegation, HTTP gateway, DTO payload shape, and service provider
- `pint.json` (Laravel coding style preset)
- `phpstan.neon` (static analysis level 5)
- `SECURITY.md` with responsible disclosure policy
- `docs/releases.md` release workflow guide

**Compatibility**
- PHP `^8.2`
- Laravel `^10.0`, `^11.0`, `^12.0`, or `^13.0`
