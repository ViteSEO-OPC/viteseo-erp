<?php

namespace App\Providers;

use App\Support\Mail\ModuleSenderResolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Per-module outbound sender identities (config/mail.php `module_senders`).
        // Registered here rather than in a module provider because it is shared: CRM,
        // HR, and eventually PMS all resolve their From address through this one object.
        $this->app->singleton(
            ModuleSenderResolver::class,
            fn ($app): ModuleSenderResolver => new ModuleSenderResolver($app->make(Repository::class)),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
