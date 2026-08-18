<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden;

use Filament\Contracts\Plugin;
use Filament\Panel;

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

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
