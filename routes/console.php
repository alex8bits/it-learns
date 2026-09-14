<?php

declare(strict_types=1);

use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:expire', function (SubscriptionService $service): void {
    $count = $service->expireDue();
    $this->info("Expired subscriptions: {$count}");
})->purpose('Move overdue subscriptions to Expired status');

Schedule::command('subscriptions:expire')->daily();
