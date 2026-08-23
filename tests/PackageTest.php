<?php

/**
 * The `preg_match` below is assigned before it is asserted on: Rector rewrites
 * `expect(preg_match(...))->toBe(1)` into `expect($subject)->toMatch(...)` and
 * takes the captures with it.
 *
 * The floor test names four files because each answers a different question and none
 * can see the others. `composer.json` is what an installer resolves against.
 * `phpstan-floor.neon` is the only thing that analyses this package at its floor —
 * a `phpVersion` range is not a union, PHPStan takes its `min`. `run-tests.yml`'s
 * matrix is the axis that makes the suite run there. And the README carries it three
 * times, twice on one line: the shields URL that actually renders, the `alt` beside
 * it, and the Requirements row. AGENTS.md §8 gates a release on that README.
 *
 * The test is a symmetric consistency check, not a one-way guard, and the reason
 * matters because it decides which assertions a future reader may drop. LOWERING the
 * floor is silent in the three files downstream of the manifest — nothing moves, and
 * the analysis, the matrix and the README go on describing the number that left.
 * RAISING it is loud only when the manifest moves ALONE: composer then aborts on the
 * older runtime, but only inside `run-tests.yml`'s floor job, since `quality.yml` is
 * pinned to the ceiling and installs happily. Raise the manifest and the matrix
 * together — how a floor is actually bumped — and composer is green everywhere and
 * this test is the only thing that goes red. Measured, both directions.
 *
 * The assertions are `toContain` on file text, the house style of `FrozenTest`. They
 * pin the number, not the behaviour: a matrix that keeps the literal and adds an
 * `exclude:` for the floor still passes.
 *
 * The export test asks `git archive` what the tarball holds instead of reading
 * `.gitattributes` and inferring. Inference was tried and thrown away: it stayed green
 * while `README.md export-ignore` or `/src export-ignore` emptied the package, and it
 * went red on clean checkouts over a `*.log` pattern, a stray untracked file or an
 * entry in the developer's global ignore file. The cost is a dependency on a checkout,
 * which is not a cost: `/tests export-ignore` means this suite only ever runs in one.
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
        ->and((string) file_get_contents($root.'/README.md'))->toContain(sprintf('badge/PHP-%s-', $floor))
        ->toContain(sprintf('alt="PHP %s"', $floor))
        ->toContain(sprintf('| PHP | `%s` |', $declared));
});

test('the distribution carries exactly what a consumer installs', function (): void {
    $entries = [];
    $status = 1;

    exec(sprintf('git -C %s archive --format=tar HEAD | tar -t', escapeshellarg(dirname(__DIR__))), $entries, $status);

    expect($status)->toBe(0);

    $top = array_unique(array_map(
        static fn (string $entry): string => explode('/', $entry)[0],
        $entries,
    ));

    sort($top);

    expect($top)->toBe([
        'CHANGELOG.md',
        'CONTRIBUTING.md',
        'LICENSE.md',
        'README.md',
        'SECURITY.md',
        'composer.json',
        'config',
        'lang',
        'resources',
        'src',
    ]);
});
