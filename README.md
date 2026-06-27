# laravel-paycorp

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hansajith18/laravel-paycorp.svg?style=flat-square)](https://packagist.org/packages/hansajith18/laravel-paycorp)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen)](https://github.com/hansajith18/laravel-paycorp/actions)

**Laravel integration for the Paycorp (Bancstac) payment gateway.**

Supports the Paycorp Paycenter Web 4.0 API — Hosted Page flow, Real-Time tokenised card payments, and Refund/Void operations — with full database audit logging and PCI DSS safe-handling baked in.

---

## Features

- **Hosted Page flow** — redirect payer to Paycorp-hosted card entry, receive callback
- **Card tokenization** — save a card during a hosted-page flow; verification charge is auto-voided
- **Real-Time flow** — charge previously saved cards instantly, without any redirect
- **Refund & Void** — session-authenticated back-office API with AES-256 encryption
- **HMAC-SHA256 request signing** — every outgoing request is signed
- **Customisable payment purposes** — swap in your own enum via `PaymentPurposeContract`
- **Auto-registered log channel** — `paycorp-YYYY-MM-DD.log` with zero config required
- **Sandbox mode** — automatic amount normalisation for the Paycorp test environment
- **Full gateway audit log** — all requests/responses written to `payment_gateway_logs` table
- **PCI DSS safe** — never stores raw card numbers or CVVs; only masked card data persisted
- **Laravel 10, 11, 12, and 13 support**

---

## Requirements

- PHP `^8.2`
- Laravel `^10.0`, `^11.0`, `^12.0`, or `^13.0`
- `ext-openssl`

---

## Installation

```bash
composer require hansajith18/laravel-paycorp
```

The service provider is auto-discovered. No manual registration required.

### Publish config and migrations

```bash
php artisan paycorp:publish
```

Or publish individually:

```bash
# Config only
php artisan vendor:publish --tag=paycorp-config

# Migrations only
php artisan vendor:publish --tag=paycorp-migrations
```

Then run migrations:

```bash
php artisan migrate
```

---

## Configuration

Add the following to your `.env` file:

```dotenv
# ── Required ──────────────────────────────────────────────────────────────────
PAYCORP_ENDPOINT=https://paycorp-smp.prod.aws.paycorp.lk/rest/service/proxy
PAYCORP_AUTH_TOKEN=your_auth_token
PAYCORP_HMAC_SECRET=your_hmac_secret

# ── Client IDs (provided by Paycorp per currency) ─────────────────────────────
PAYCORP_CLIENT_ID_LKR=your_lkr_client_id
PAYCORP_CLIENT_ID_USD=your_usd_client_id

# ── Tokenised payment client IDs (required for card-save / Real-Time flow) ────
PAYCORP_TOKEN_CLIENT_ID_LKR=your_tokenize_lkr_client_id
PAYCORP_TOKEN_CLIENT_ID_USD=your_tokenize_usd_client_id

# ── Return URL (Paycorp redirects here after card entry) ──────────────────────
PAYCORP_RETURN_URL="${APP_URL}/api/payments/paycorp/return"

# ── Optional ──────────────────────────────────────────────────────────────────
PAYCORP_DEFAULT_CURRENCY=LKR
PAYCORP_SANDBOX=false
PAYCORP_API_VERSION=1.04
PAYCORP_TIMEOUT=30
PAYCORP_CONNECT_TIMEOUT=10
PAYCORP_RETRY_TIMES=2
PAYCORP_RETRY_SLEEP_MS=500

# ── Refund / Void credentials (required only if using refund features) ─────────
PAYCORP_REFUND_ENDPOINT=https://paycorp-console.prod.aws.paycorp.lk
PAYCORP_REFUND_USERNAME=your_console_username
PAYCORP_REFUND_PASSWORD=your_console_password
PAYCORP_REFUND_IV_PHRASE=your_iv_phrase
PAYCORP_REFUND_AES_SECRET=your_aes_secret
PAYCORP_REFUND_DEVICE_ID=your_device_id

# ── Logging ───────────────────────────────────────────────────────────────────
PAYCORP_LOG_CHANNEL=paycorp
PAYCORP_LOG_LEVEL=debug
PAYCORP_LOG_DAYS=14
```

The package auto-registers a `paycorp` daily log channel — no changes to `config/logging.php` are needed. To redirect logs to an existing channel (e.g. `stack`), set `PAYCORP_LOG_CHANNEL=stack`.

---

## Payment Flows Overview

The package supports three distinct payment flows:

```
┌─────────────────────────────────────────────────────────────────────┐
│ FLOW A — Normal Purchase (Hosted Page)                              │
│                                                                     │
│  initializePayment(purpose: PURCHASE)                               │
│       │                                                             │
│       ▼  redirect to Paycorp hosted page                            │
│  User enters card details                                           │
│       │                                                             │
│       ▼  Paycorp redirects back to PAYCORP_RETURN_URL               │
│  completePayment(reqId)  →  PaymentSucceeded / PaymentFailed event  │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ FLOW B — Save a Card (Card Registration / Tokenization)             │
│                                                                     │
│  initializePayment(purpose: CARD_REGISTRATION, tokenize: true)      │
│       │                                                             │
│       ▼  redirect to Paycorp hosted page                            │
│  User enters card details                                           │
│       │                                                             │
│       ▼  Paycorp redirects back to PAYCORP_RETURN_URL               │
│  completePayment(reqId)  →  card token saved in saved_payments      │
│       │                                                             │
│       ▼  automatically (queued job)                                 │
│  voidPayment()  →  verification charge reversed (no cost to user)   │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│ FLOW C — Charge a Saved Card (Real-Time, no redirect)               │
│                                                                     │
│  chargeWithSavedPayment(savedPayment)                               │
│       │                                                             │
│       ▼  charged immediately (no hosted page redirect)              │
│  PaymentSucceeded / PaymentFailed event                             │
└─────────────────────────────────────────────────────────────────────┘
```

> Use `PaycenterPaymentService` for fully persisted, audited, event-dispatching workflows (recommended). Use `PaycorpClient` directly when you need raw gateway access without database involvement.

---

## Usage

### Flow A — Normal Purchase (Hosted Page)

The payer is redirected to the Paycorp-hosted card entry page. After they complete payment, Paycorp redirects them back to your `PAYCORP_RETURN_URL` with a `reqid` parameter. You call `completePayment` in that callback to finalise the transaction.

#### Step 1 — Initialise the payment

```php
use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;

$service = app(PaycenterPaymentService::class);

$payment = $service->initializePayment(
    userId:      auth()->id(),
    amountCents: 50000,                      // 500.00 LKR
    purpose:     PaymentPurposeEnum::PURCHASE,
    currency:    'LKR',
    payableType: \App\Models\Order::class,   // optional: link to a model
    payableId:   $order->id,
);

// Redirect the user to the Paycorp-hosted payment page
return redirect($payment->payment_page_url);
```

#### Step 2 — Complete the payment (your callback route)

Paycorp sends the payer back to `PAYCORP_RETURN_URL?reqid=...`. Handle it here:

```php
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;

$service = app(PaycenterPaymentService::class);

// reqid comes from the Paycorp redirect query string
$payment = $service->completePayment($request->query('reqid'));

if ($payment->isCompleted()) {
    // payment approved — fulfil the order
} else {
    // payment failed or declined
    // $payment->gateway_response_code, $payment->gateway_response_text
}
```

`completePayment` is **idempotent** — a database lock prevents the gateway from being called more than once per `reqid`, even under concurrent callbacks.

---

### Flow B — Save a Card (Card Registration / Tokenization)

Card registration uses the **same two-step hosted-page flow** as a normal purchase. The only differences are the `purpose` and `tokenize: true` flag. Paycorp charges a small verification amount, but the package automatically **voids (reverses) that charge** after the card token is saved — the customer is never charged.

#### Step 1 — Initialise card registration

```php
use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;

$service = app(PaycenterPaymentService::class);

$payment = $service->initializePayment(
    userId:      auth()->id(),
    amountCents: 100,                               // small verification amount
    purpose:     PaymentPurposeEnum::CARD_REGISTRATION,
    currency:    'LKR',
    tokenize:    true,                              // instruct Paycorp to tokenize the card
);

return redirect($payment->payment_page_url);
```

#### Step 2 — Complete card registration (same callback route as Flow A)

```php
$payment = $service->completePayment($request->query('reqid'));

// After completePayment:
// ✓ Card token saved to saved_payments table
// ✓ VoidTokenizationCharge job dispatched automatically (reverses the charge)
// ✓ PaymentSucceeded event fired
```

> **What happens automatically after completion:**
> - The card token is stored in `saved_payments` and linked to the user.
> - The `VoidTokenizationCharge` queued job fires, sending a void request to Paycorp to reverse the verification charge. The job retries up to 5 times with exponential backoff.
> - The `Payment` status transitions from `completed` → `voided` after a successful void.

---

### Flow C — Charge a Saved Card (Real-Time, No Redirect)

Once a card has been saved via Flow B, you can charge it directly without any hosted-page redirect. The charge result is synchronous and immediately available.

```php
use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;
use Hansajith18\LaravelPaycorp\Models\SavedPayment;
use Hansajith18\LaravelPaycorp\Services\PaycenterPaymentService;

$service      = app(PaycenterPaymentService::class);
$savedPayment = SavedPayment::where('user_id', auth()->id())->firstOrFail();

$payment = $service->chargeWithSavedPayment(
    userId:       auth()->id(),
    amountCents:  50000,                       // 500.00 LKR
    purpose:      PaymentPurposeEnum::PURCHASE,
    savedPayment: $savedPayment,
    currency:     'LKR',
    payableType:  \App\Models\Order::class,    // optional
    payableId:    $order->id,
);

if ($payment->isCompleted()) {
    // charge approved — fulfil the order immediately (no callback needed)
} else {
    // charge failed or declined
}
```

No redirect is involved — `chargeWithSavedPayment` returns the final `Payment` model synchronously after the gateway responds.

---

### Refund

```php
use Hansajith18\LaravelPaycorp\PaycorpClient;

$client = app(PaycorpClient::class);

$response = $client->refund(
    originalTxnReference: $payment->gateway_txn_reference,
    amountCents:          50000,
    clientRef:            'REFUND-' . $refundId,
    currency:             'LKR',
    comment:              'Customer request',
);

if ($response->isApproved()) {
    // refund approved
}
```

Refund credentials (`PAYCORP_REFUND_*`) must be configured. The refund gateway is not available by default.

---

### Direct Gateway Access (Advanced)

For scenarios where you don't need database persistence or events, use `PaycorpClient` directly:

```php
use Hansajith18\LaravelPaycorp\PaycorpClient;

$client = app(PaycorpClient::class);
// or: $client = app('paycorp');

// Hosted-page init (returns only the payment page URL, no DB record)
$initResponse = $client->initPayment(
    amountCents: 50000,
    clientRef:   'ORDER-' . $orderId,
    returnUrl:   route('payments.callback'),
    currency:    'LKR',
);
return redirect($initResponse->paymentPageUrl);

// Complete (returns only the gateway response, no DB update)
$completeResponse = $client->completePayment($reqId, 'LKR');

// Real-time charge against a token
$rtResponse = $client->realTimePayment(
    cardToken:   $savedPayment->token,
    cardExpiry:  $savedPayment->card_expiry,
    amountCents: 50000,
    clientRef:   'PAY-' . $orderId,
    currency:    'LKR',
);
```

---

### Events

Both `PaycenterPaymentService` flows dispatch events after a gateway response. Listen to them in your `AppServiceProvider` or a dedicated listener:

```php
use Hansajith18\LaravelPaycorp\Events\PaymentSucceeded;
use Hansajith18\LaravelPaycorp\Events\PaymentFailed;

Event::listen(PaymentSucceeded::class, function ($event) {
    $payment = $event->payment;
    // fulfil order, send receipt, etc.
    // $payment->gateway_txn_reference, $payment->card_last4, etc.
});

Event::listen(PaymentFailed::class, function ($event) {
    $payment = $event->payment;
    // notify user, release reservation, etc.
    // $payment->gateway_response_code, $payment->gateway_response_text
});
```

---

## Custom Payment Purposes

The package ships with a built-in `PaymentPurposeEnum` containing generic cases:

| Case | Value | Label |
|------|-------|-------|
| `PURCHASE` | `purchase` | Purchase |
| `SUBSCRIPTION` | `subscription` | Subscription |
| `CARD_REGISTRATION` | `card_registration` | Card Registration |
| `WALLET_TOPUP` | `wallet_topup` | Wallet Top-up |
| `REFUND` | `refund` | Refund |

You can replace this entirely with your own string-backed enum by implementing `PaymentPurposeContract`:

```php
// app/Enums/PaymentPurpose.php
namespace App\Enums;

use Hansajith18\LaravelPaycorp\Contracts\PaymentPurposeContract;

enum PaymentPurpose: string implements PaymentPurposeContract
{
    case ORDER_PAYMENT  = 'order_payment';
    case TOP_UP         = 'top_up';
    case CARD_SAVE      = 'card_save';      // your card-registration purpose

    public function label(): string
    {
        return match ($this) {
            self::ORDER_PAYMENT => 'Order Payment',
            self::TOP_UP        => 'Wallet Top-up',
            self::CARD_SAVE     => 'Card Registration',
        };
    }
}
```

Register it in `config/paycorp.php`:

```php
'purpose_class' => \App\Enums\PaymentPurpose::class,

// Tell the package which value represents card registration so it knows
// when to dispatch the auto-void job after tokenisation completes:
'card_registration_purpose' => 'card_save',
```

Your enum is now used for `$payment->purpose` casting, client-reference prefix generation, and the gateway comment label — no other changes needed.

---

## Payable Model Integration (Optional)

### Linking a payment to a model

Pass `payableType` (fully-qualified class name) and `payableId` to `initializePayment` or `chargeWithSavedPayment`:

```php
$payment = $service->initializePayment(
    userId:      auth()->id(),
    amountCents: 50000,
    purpose:     PaymentPurposeEnum::PURCHASE,
    payableType: \App\Models\Order::class,   // must be a real, existing class
    payableId:   $order->id,
);
```

> **Note:** Passing a class name that does not exist throws a `ValidationException` immediately, before any DB insert, to prevent a confusing "Class not found" error from Eloquent at query time.

### Auto-updating a status column on the payable

When a payment completes or fails, the service can automatically update a column on the related payable model. Configure this in `config/paycorp.php`:

```php
// Which model class base-names to update
'payable_status_classes' => ['Order', 'Booking'],

// Which column to write 'paid' / 'failed' into (default: payment_status)
'payable_status_column'  => 'payment_status',
```

Leave `payable_status_classes` empty (the default) and handle status updates in your own event listeners instead — this gives full control and keeps your domain logic in one place.

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `payments` | One row per payment attempt; tracks status, masked card info, gateway references, polymorphic payable link |
| `payment_gateway_logs` | Full request/response audit trail per gateway operation (every PAYMENT_INIT, PAYMENT_COMPLETE, PAYMENT_REAL_TIME, PAYMENT_VOID) |
| `saved_payments` | Tokenised cards per user (for Flow C — Real-Time charges) |

### Payment Status Transitions

```
PENDING  →  AWAITING_CAPTURE  →  COMPLETED
                                     │
                                     ▼ (card registration only)
                                  VOIDED   (auto-void job reverses the charge)

PENDING  →  FAILED
AWAITING_CAPTURE  →  FAILED
```

---

## Sandbox Mode

Set `PAYCORP_SANDBOX=true` when using the Paycorp test environment. The package automatically:

- Strips sub-unit cents (e.g. `5389.20 LKR → 5389.00 LKR`)
- Enforces a minimum amount of `200` cents (2.00 LKR)

No code changes are needed between sandbox and production.

---

## Testing

```bash
composer test
```

Or directly:

```bash
./vendor/bin/phpunit
```

### Quality Tooling

```bash
# Code style (Laravel Pint)
composer format

# Static analysis (PHPStan level 5)
composer analyse
```

---

## Security

- All API requests are signed with HMAC-SHA256
- TLS verification enforced (`verify: true`) — HTTP connections always use TLS
- Card numbers and CVVs are **never stored** — only masked card data persisted
- `toSafeArray()` explicitly excludes cardholder name, raw expiry, and internal comments
- Refund credentials are AES-256-CBC encrypted before transmission
- Gateway audit logs sanitise all sensitive fields before writing to the database
- Missing client IDs, refund credentials, or endpoint config throw clear `RuntimeException` messages at startup rather than sending malformed requests to the gateway

Please see [SECURITY.md](SECURITY.md) for how to report a security vulnerability.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

For maintainers: see [docs/releases.md](docs/releases.md) for the full release workflow guide.

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

---

## Credits

- [Navod Hansajith](https://github.com/hansajith18)
