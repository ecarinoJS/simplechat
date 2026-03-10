<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AzurePubSubConfig;
use App\Services\AzurePubSubTokenService;
use App\Services\AzurePubSubPublisher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Azure Web PubSub services as singletons
        $this->app->singleton(AzurePubSubConfig::class);
        $this->app->singleton(AzurePubSubTokenService::class);
        $this->app->singleton(AzurePubSubPublisher::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
