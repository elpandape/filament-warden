<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden;

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

        $this->publishes([
            __DIR__.'/../config/filament-warden.php' => config_path('filament-warden.php'),
        ], 'filament-warden-config');

        $this->publishes([
            __DIR__.'/../lang' => lang_path('vendor/filament-warden'),
        ], 'filament-warden-translations');
    }
}
