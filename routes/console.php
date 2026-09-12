<?php

use App\V2\Services\CallOrchestrationService;
use App\Jobs\V2\PostOwnerAttentionDigestsJob;
use App\Jobs\V2\ProcessNurtureDueLeadsJob;
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

Schedule::command('campaigns:dispatch-due')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('outreach:dispatch-due')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('outreach:enrich-email-waves')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('outreach:enrich-email-waves')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:recover --release-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:monitor-depth')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('ai:prompt-regression-weekly --days=7 --limit=50')
    ->weeklyOn(1, '07:30')
    ->withoutOverlapping()
    ->runInBackground();

Artisan::command('nurture:flag-due', function () {
    ProcessNurtureDueLeadsJob::dispatchSync();
    $this->info('Nurture due flags updated.');
})->purpose('Mark nurture leads whose follow-up date has passed');

Schedule::command('nurture:flag-due')->dailyAt('08:00')->withoutOverlapping();

Artisan::command('socifusion:attention-digest {slot=morning}', function (string $slot) {
    if (! in_array($slot, ['morning', 'evening'], true)) {
        $this->error('Slot must be morning or evening.');

        return 1;
    }

    PostOwnerAttentionDigestsJob::dispatchSync($slot);
    $this->info("Attention digests processed ({$slot}).");

    return 0;
})->purpose('Post owner attention digests (morning/evening) when inbox needs you');

$digestMorning = (string) config('socifusion_ai.attention_digest.morning_at', '08:00');
$digestEvening = (string) config('socifusion_ai.attention_digest.evening_at', '18:00');

Schedule::command('socifusion:attention-digest morning')
    ->dailyAt($digestMorning)
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('socifusion:attention-digest evening')
    ->dailyAt($digestEvening)
    ->withoutOverlapping()
    ->runInBackground();
