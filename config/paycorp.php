<?php

use Hansajith18\LaravelPaycorp\Enums\PaymentPurposeEnum;

return [

    /*
    |--------------------------------------------------------------------------
    | Paycorp API Endpoint
    |--------------------------------------------------------------------------
    |
    | The REST service proxy URL provided by Paycorp/Bancstac.
    | Must use HTTPS — TLS 1.2+ is enforced by the HTTP client.
    |
    | Example: https://paycorp-smp.prod.aws.paycorp.lk/rest/service/proxy
    |
    */
    'endpoint' => env('PAYCORP_ENDPOINT'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Token
    |--------------------------------------------------------------------------
    |
    | The AUTHTOKEN header value provided by Paycorp.
    |
    */
    'auth_token' => env('PAYCORP_AUTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HMAC Secret
    |--------------------------------------------------------------------------
    |
    | Used to generate HMAC-SHA256 signatures for request integrity.
    |
    */
    'hmac_secret' => env('PAYCORP_HMAC_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Client IDs
    |--------------------------------------------------------------------------
    |
    | Paycorp client IDs per currency. Used to route transactions
    | to the correct acquiring bank configuration.
    |
    */
    'client_id_lkr' => env('PAYCORP_CLIENT_ID_LKR'),
    'tokenize_payment_client_id_lkr' => env('PAYCORP_TOKEN_CLIENT_ID_LKR'),
    'client_id_usd' => env('PAYCORP_CLIENT_ID_USD'),
    'tokenize_payment_client_id_usd' => env('PAYCORP_TOKEN_CLIENT_ID_USD'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 currency code. Amounts are sent in minor units (cents).
    |
    */
    'default_currency' => env('PAYCORP_DEFAULT_CURRENCY', 'LKR'),

    /*
    |--------------------------------------------------------------------------
    | Return URL
    |--------------------------------------------------------------------------
    |
    | The URL Paycorp redirects the payer to after card entry.
    | This endpoint receives the ReqID via GET parameter.
    |
    | Example: https://yourapp.com/api/payments/paycorp/return
    |
    */
    'return_url' => env('PAYCORP_RETURN_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('PAYCORP_TIMEOUT', 30),
    'connect_timeout' => (int) env('PAYCORP_CONNECT_TIMEOUT', 10),
    'retry_times' => (int) env('PAYCORP_RETRY_TIMES', 2),
    'retry_sleep_ms' => (int) env('PAYCORP_RETRY_SLEEP_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The Paycorp API protocol version to use. Affects request date format:
    |   1.04 → "Y-m-d H:i:s"
    |   1.5+ → "Y-m-d\TH:i:s.vO"
    |
    */
    'api_version' => env('PAYCORP_API_VERSION', '1.04'),

    /*
    |--------------------------------------------------------------------------
    | Refund / Void Credentials
    |--------------------------------------------------------------------------
    |
    | The refund API uses session-based authentication. These credentials
    | are provided by Paycorp for back-office (console) operations.
    |
    | Set PAYCORP_REFUND_USERNAME and PAYCORP_REFUND_PASSWORD to enable
    | refund/void features. Leave empty to disable them.
    |
    */
    'refund_endpoint' => env('PAYCORP_REFUND_ENDPOINT'),
    'refund_username' => env('PAYCORP_REFUND_USERNAME'),
    'refund_password' => env('PAYCORP_REFUND_PASSWORD'),
    'refund_iv_phrase' => env('PAYCORP_REFUND_IV_PHRASE'),
    'refund_aes_secret' => env('PAYCORP_REFUND_AES_SECRET'),
    'refund_device_id' => env('PAYCORP_REFUND_DEVICE_ID', 'paycorp-refund-device-id'),

    /*
    |--------------------------------------------------------------------------
    | Sandbox Mode
    |--------------------------------------------------------------------------
    |
    | Set to true when pointing at the Paycorp test environment.
    |
    | The test IPG has two documented constraints not applicable to production:
    |   1. Decimal (sub-unit) amounts are rejected — amounts must be whole
    |      currency units (e.g. 5389 LKR, not 5389.20 LKR).
    |   2. A minimum of 200 cents (2.00 LKR) is required.
    |
    | When sandbox=true, PaycenterPaymentService automatically normalises
    | every amountCents value before it reaches the gateway.
    |
    */
    'sandbox' => env('PAYCORP_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The log channel used by the package. The service provider registers a
    | "paycorp" daily channel automatically — no changes to logging.php needed.
    |
    | To redirect package logs to an existing channel:
    |   PAYCORP_LOG_CHANNEL=stack
    |
    */
    'log_channel' => env('PAYCORP_LOG_CHANNEL', 'paycorp'),
    'log_level' => env('PAYCORP_LOG_LEVEL', 'debug'),
    'log_days' => (int) env('PAYCORP_LOG_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model representing users in your application.
    |
    */
    'user_model' => env('PAYCORP_USER_MODEL', 'App\Models\User'),

    /*
    |--------------------------------------------------------------------------
    | Payment Purpose Enum Class
    |--------------------------------------------------------------------------
    |
    | The enum class used to cast the "purpose" column on the Payment model.
    | Swap this out with your own enum that implements PaymentPurposeContract
    | to use custom payment purposes throughout your application.
    |
    | Your enum must be string-backed and implement:
    |   Hansajith18\LaravelPaycorp\Contracts\PaymentPurposeContract
    |
    | Example:
    |   'purpose_class' => \App\Enums\MyPaymentPurpose::class,
    |
    */
    'purpose_class' => PaymentPurposeEnum::class,

    /*
    |--------------------------------------------------------------------------
    | Card Registration Purpose Value
    |--------------------------------------------------------------------------
    |
    | The raw string value of the purpose enum case that represents a card
    | registration (tokenisation) payment. After a successful card registration
    | payment, PaycenterPaymentService automatically dispatches the
    | VoidTokenizationCharge job to reverse the verification charge.
    |
    | If you use a custom purpose enum, set this to the matching value string.
    |
    */
    'card_registration_purpose' => 'card_registration',

    /*
    |--------------------------------------------------------------------------
    | Payable Status Classes
    |--------------------------------------------------------------------------
    |
    | When a payment completes or fails, PaycenterPaymentService can update
    | a status column on the related payable model — if its class base-name
    | is listed here.
    |
    | Example: ['Order', 'Booking']
    |
    | Leave empty (default) to handle payable updates in your event listeners.
    |
    */
    'payable_status_classes' => [],

    /*
    |--------------------------------------------------------------------------
    | Payable Status Column
    |--------------------------------------------------------------------------
    |
    | The column name updated on the payable model when a payment completes
    | or fails. Defaults to "payment_status".
    |
    */
    'payable_status_column' => env('PAYCORP_PAYABLE_STATUS_COLUMN', 'payment_status'),

];
