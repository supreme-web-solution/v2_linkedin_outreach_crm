<?php

use App\V2\Services\CallOrchestrationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('calls:dispatch-due', function (CallOrchestrationService $orchestration) {
    $result = $orchestration->dispatchDue();
    $this->info("Dispatched {$result['messages_sent']} call message(s) and {$result['reminders_sent']} reminder(s).");
})->purpose('Send due call messages and pre-call reminders via Unipile');

Schedule::command('calls:dispatch-due')->everyMinute();

Schedule::command('queue:recover --release-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:monitor-depth')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
