<?php

declare(strict_types=1);

use App\Services\Practice\PracticeEnvironmentPruner;
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

Artisan::command('practice:prune-environments', function (PracticeEnvironmentPruner $pruner): void {
    $result = $pruner->prune();

    $this->info("Pruned practice containers: {$result['containers']}, environments: {$result['environments']}");
})->purpose('Remove stale practice environment containers and mark dangling rows Destroyed');

Schedule::command('subscriptions:expire')->daily();

Schedule::command('practice:prune-environments')->everyFiveMinutes()->withoutOverlapping();
