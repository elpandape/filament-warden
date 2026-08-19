<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden;

use ElPandaPe\FilamentWarden\Console\AssignRoleCommand;
use ElPandaPe\FilamentWarden\Console\AuditCommand;
use ElPandaPe\FilamentWarden\Policies\PermissionPolicy;
use ElPandaPe\FilamentWarden\Policies\RolePolicy;
use ElPandaPe\Warden\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class FilamentWardenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filament-warden.php', 'filament-warden');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'filament-warden');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-warden');

        $this->registerPolicies();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AssignRoleCommand::class,
                AuditCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/filament-warden.php' => config_path('filament-warden.php'),
            ], 'filament-warden-config');

            $this->publishes([
                __DIR__.'/../lang' => lang_path('vendor/filament-warden'),
            ], 'filament-warden-translations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/filament-warden'),
            ], 'filament-warden-views');
        }
    }

    /**
     * Both models live in vendor, and Laravel's guessing never reaches this
     * namespace. Without this, Filament finds no policy and answers `allow`.
     *
     * Registered against the configured classes, not the shipped ones: an
     * application may swap either. And registered unconditionally — an
     * application that wants its own policy registers it in its own service
     * provider, which boots after every package provider and wins.
     */
    private function registerPolicies(): void
    {
        $context = Context::resolve();

        Gate::policy($context->roleClass(), RolePolicy::class);
        Gate::policy($context->permissionClass(), PermissionPolicy::class);
    }
}
