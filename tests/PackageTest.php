<?php

/**
 * The `preg_match` below is assigned before it is asserted on: Rector rewrites
 * `expect(preg_match(...))->toBe(1)` into `expect($subject)->toMatch(...)` and
 * takes the captures with it.
 *
 * The floor test names four places because each answers a different question and
 * none can see the others. `composer.json` is what an installer resolves against.
 * `phpstan-floor.neon` is the only thing that analyses this package at its floor —
 * a `phpVersion` range is not a union, PHPStan takes its `min`. `run-tests.yml`'s
 * matrix is what proves the suite RUNS there. And the README states it twice, which
 * AGENTS.md §8 gates a release on. Raising the manifest alone is silent in all four.
 */

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

test('the PHP floor is the same number everywhere that states it', function (): void {
    $root = dirname(__DIR__);

    /** @var array{require: array{php: string}} $composer */
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    $declared = $composer['require']['php'];
    $matched = preg_match('/^\^(\d+)\.(\d+)$/', $declared, $parts);

    expect($matched)->toBe(1);

    /** @var array{non-falsy-string, numeric-string, numeric-string} $parts */
    $floor = $parts[1].'.'.$parts[2];

    expect((string) file_get_contents($root.'/phpstan-floor.neon'))
        ->toContain(sprintf('phpVersion: %d%02d00', (int) $parts[1], (int) $parts[2]))
        ->and((string) file_get_contents($root.'/.github/workflows/run-tests.yml'))->toContain(sprintf("php: ['%s', ", $floor))
        ->and((string) file_get_contents($root.'/README.md'))->toContain(sprintf('alt="PHP %s"', $floor))
        ->toContain(sprintf('| PHP | `%s` |', $declared));
});
