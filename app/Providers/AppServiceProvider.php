<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureApiRateLimits();
        $this->configureHorizonDevCommands();
        $this->configureHorizonForWindows();
        $this->configureHorizonSlackAlerts();
        $this->configureSeoDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureApiRateLimits(): void
    {
        RateLimiter::for('v2-auth', function ($request) {
            $key = (string) $request->ip().'|'.(string) $request->input('email');
            return [
                Limit::perMinute(10)->by($key),
            ];
        });

        RateLimiter::for('v2-extension', function ($request) {
            $user = $request->attributes->get('v2User');
            $key = $user ? 'user:'.$user->id : 'ip:'.$request->ip();

            return [
                Limit::perMinute(120)->by($key),
            ];
        });
    }

    /**
     * Laravel 13: DevCommands must be registered in application code.
     */
    protected function configureHorizonDevCommands(): void
    {
        if (! class_exists(\Illuminate\Foundation\DevCommands::class)) {
            return;
        }

        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return;
        }

        \Illuminate\Foundation\DevCommands::artisan('horizon', 'horizon');
        \Illuminate\Foundation\DevCommands::except('queue');
    }

    /**
     * Horizon ships Unix-only `exec ...` command strings. Drop that on Windows
     * so supervisors/workers spawn via cmd.exe successfully.
     */
    protected function configureHorizonForWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        if (! class_exists(\Laravel\Horizon\WorkerCommandString::class)) {
            return;
        }

        \Laravel\Horizon\WorkerCommandString::$command = '@php artisan horizon:work';
        \Laravel\Horizon\SupervisorCommandString::$command = '@php artisan horizon:supervisor';
    }

    protected function configureHorizonSlackAlerts(): void
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return;
        }

        $webhook = trim((string) config('services.ops.slack_webhook_url', ''));
        if ($webhook === '') {
            return;
        }

        \Laravel\Horizon\Horizon::routeSlackNotificationsTo($webhook, '#ops');
    }

    protected function configureSeoDefaults(): void
    {
        if (! function_exists('seo')) {
            return;
        }

        $appName = (string) config('app.name', 'Call Manager');

        seo()
            ->site($appName)
            ->title(default: $appName)
            ->description(default: 'LinkedIn outreach, call scheduling, and lead management.')
            ->type('website')
            ->twitter();
    }
}
