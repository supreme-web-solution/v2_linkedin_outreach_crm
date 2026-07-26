<?php

namespace App\V2\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Server-side SEO for guest/auth pages (LinkedIn / social crawlers).
 */
class AuthPageSeoService
{
    /**
     * @param  array{title?: string, description?: string, path?: string, image?: string, imageAlt?: string}  $overrides
     */
    public function apply(Request $request, array $overrides = []): void
    {
        $appName = (string) config('app.name', 'Call Manager');

        $title = $overrides['title'] ?? "{$appName} — Sign in";
        $description = $overrides['description']
            ?? 'LinkedIn outreach, multi-channel campaigns, call scheduling, and lead management — all in one workspace.';
        $path = $overrides['path'] ?? $request->path();
        $image = $overrides['image'] ?? URL::asset('images/seo/auth-og.png');
        $imageAlt = $overrides['imageAlt'] ?? 'Sign in to your outreach workspace';

        seo()
            ->title($title)
            ->description($description)
            ->image($image)
            ->type('website')
            ->site($appName)
            ->url(URL::to('/'.ltrim($path, '/')));

        seo()->rawTag('<meta property="og:image:width" content="1200">');
        seo()->rawTag('<meta property="og:image:height" content="630">');
        seo()->rawTag('<meta property="og:image:alt" content="'.e($imageAlt).'">');
    }

    public function login(Request $request): void
    {
        $appName = (string) config('app.name', 'Call Manager');

        $this->apply($request, [
            'title' => "Log in — {$appName}",
            'description' => 'Sign in to manage LinkedIn outreach, book calls with prospects, and track your pipeline.',
            'path' => 'login',
            'imageAlt' => 'Log in to your outreach workspace',
        ]);
    }

    public function register(Request $request): void
    {
        $appName = (string) config('app.name', 'Call Manager');

        $this->apply($request, [
            'title' => "Create account — {$appName}",
            'description' => 'Create your account and start automating LinkedIn outreach, scheduling calls, and enriching leads.',
            'path' => 'register',
            'imageAlt' => 'Create your outreach workspace account',
        ]);
    }

    public function forgotPassword(Request $request): void
    {
        $appName = (string) config('app.name', 'Call Manager');

        $this->apply($request, [
            'title' => "Reset password — {$appName}",
            'description' => 'Reset your password to get back into your outreach workspace.',
            'path' => 'forgot-password',
        ]);
    }

    public function licenseSignup(Request $request, string $variant): void
    {
        $appName = (string) config('app.name', 'Call Manager');

        $labels = [
            'fe' => 'Activate FE access',
            'bundle' => 'Activate bundle access',
            'reseller' => 'Reseller registration',
        ];

        $paths = [
            'fe' => 'auth/fe',
            'bundle' => 'auth/bundle-access',
            'reseller' => 'create-reseller',
        ];

        $label = $labels[$variant] ?? 'Create account';

        $this->apply($request, [
            'title' => "{$label} — {$appName}",
            'description' => "{$label} for {$appName}. LinkedIn outreach, calls, and CRM in one place.",
            'path' => $paths[$variant] ?? 'register',
        ]);
    }
}
