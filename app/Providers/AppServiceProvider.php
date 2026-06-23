<?php

namespace App\Providers;

use App\Services\Telegram\TelegramClientFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the long-lived queue workers / bot:run reuse one factory
        // instance — and its warm SOCKS5+TLS connections — across every job.
        $this->app->singleton(TelegramClientFactory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
