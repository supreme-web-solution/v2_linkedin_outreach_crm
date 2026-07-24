<?php

namespace App\Providers;

use App\V2\Integrations\ProviderManager;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\ServiceProvider;

class V2IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderManager::class, function () {
            return new ProviderManager([
                'unipile' => app(UnipileProvider::class),
            ]);
        });
    }
}
