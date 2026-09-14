<?php

declare(strict_types=1);

use App\Http\Controllers\Subscription\PaymentWebhookController;
use App\Http\Controllers\Subscription\SubscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

// Публичная страница тарифов (гостевая зона, без авторизации).
Route::get('/pricing', fn () => Inertia::render('Pricing', [
    'premium' => config('payments.premium'),
]))->name('pricing');

// Личный кабинет подписки (авторизованная зона).
Route::middleware('auth:web')->group(function (): void {
    Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription');
    Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout'])
        ->middleware('throttle:subscription')->name('subscription.checkout');
    Route::get('/subscription/checkout/return', [SubscriptionController::class, 'return'])
        ->name('subscription.checkout.return');
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])
        ->middleware('throttle:subscription')->name('subscription.cancel');
});

// Webhook платёжного провайдера: публичный, без CSRF (bootstrap/app.php) —
// полезная нагрузка провайдера валидируется внутри гейта.
Route::post('/subscription/webhook', PaymentWebhookController::class)
    ->middleware('throttle:payment-webhook')->name('subscription.webhook');
