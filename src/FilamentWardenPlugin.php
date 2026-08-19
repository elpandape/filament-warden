<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;

final class FilamentWardenPlugin implements Plugin
{
    public static function make(): self
    {
        return resolve(self::class);
    }

    public function getId(): string
    {
        return 'filament-warden';
    }

    /**
     * Registered here and not in `boot()`: `Panel::boot()` never runs in the
     * console, so `php artisan filament:assets` would copy nothing and the panel
     * would ask for a stylesheet that answers 404.
     *
     * The package name is the composer one, literally: anything else makes the
     * cache-busting query string fall back to Filament's own version.
     */
    public function register(Panel $panel): void
    {
        $panel->resources([
            RoleResource::class,
            PermissionResource::class,
        ])->assets([
            Css::make('permission-grid', __DIR__.'/../resources/css/permission-grid.css'),
            AlpineComponent::make('permission-grid', __DIR__.'/../resources/js/permission-grid.js'),
        ], package: 'elpandape/filament-warden');
    }

    public function boot(Panel $panel): void {}
}
