<?php

declare(strict_types=1);

use App\Services\Payments\DummyPaymentGateway;

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
    |
    | Whitelist of gateway implementations (the same pattern the future
    | config/ai.php will follow). Adding a production gateway (Stage 3.1)
    | = a new implementation class + one line here, nothing else changes.
    |
    */

    'gateways' => [
        'dummy' => DummyPaymentGateway::class,
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
