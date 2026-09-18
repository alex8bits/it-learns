<?php

declare(strict_types=1);

use App\Services\Payments\DummyPaymentGateway;
use App\Services\Payments\YooKassaPaymentGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    |
    | The active payment gateway, selected via PAYMENT_PROVIDER (.env).
    | Must be a key of the `gateways` whitelist below, otherwise the
    | application fails loudly on boot (AppServiceProvider).
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | Gateway Whitelist
    |--------------------------------------------------------------------------
    |
    | Whitelist of gateway implementations (the same pattern config/ai.php
    | follows). `dummy` — fictitious gateway for dev/tests/demo;
    | `yookassa` — the production gateway (Stage 9, direct HTTP via the
    | Http facade, no SDK). Adding another gateway = a new implementation
    | class + one line here, nothing else changes.
    |
    */

    'gateways' => [
        'dummy' => DummyPaymentGateway::class,
        'yookassa' => YooKassaPaymentGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | YooKassa Credentials
    |--------------------------------------------------------------------------
    |
    | Merchant credentials for the production gateway (Stage 9), read at
    | call time by YooKassaPaymentGateway (Basic auth shopId:secretKey).
    | Required only when the active provider is `yookassa` — validated
    | fail-loud on boot (AppServiceProvider). YooKassa has no webhook
    | secret: notifications are re-verified through the API instead.
    |
    */

    /** @var array{shop_id: string|null, secret_key: string|null} */
    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Premium Tier Parameters
    |
    | Price is a placeholder until the product decision is made (the price
    | is not fixed in docs/concept.md). Amount is in minor units — an
    | integer, never a float.
    |
    */

    'premium' => [
        'amount_minor' => 99900, // 999.00 in minor units
        'currency' => 'RUB',
        'duration' => '1 month',
    ],

];
