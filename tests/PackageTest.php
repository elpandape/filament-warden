<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;
use Illuminate\Support\ServiceProvider;

pest()->extend(TestCase::class);

test('the provider composer auto-discovers actually exists', function (): void {
    /** @var array{extra: array{laravel: array{providers: list<string>}}} $composer */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $providers = $composer['extra']['laravel']['providers'];

    expect($providers)->toHaveCount(1);

    $provider = $providers[0];

    expect(class_exists($provider))->toBeTrue()
        ->and(is_subclass_of($provider, ServiceProvider::class))->toBeTrue();
});
