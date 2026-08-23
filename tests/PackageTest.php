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
 * The floor test is a symmetric consistency check, and the reason matters because it
 * decides which of its assertions a future reader may drop. LOWERING the floor is
 * silent in the three files downstream of the manifest — nothing moves, and the
 * analysis, the matrix and the README go on describing the number that left. RAISING
 * it is loud in composer, but only where composer runs against the older runtime, so
 * `run-tests.yml`'s floor job alone; it is also loud in Rector, everywhere, because
 * `rector.php`'s `withPhpSets()` follows `require.php` and not the runtime — measured
 * at the ceiling with the manifest raised: `AddOverrideAttributeToOverriddenPropertiesRector`
 * fires and the gate exits 2. What NOTHING else catches, in either direction, is
 * `phpstan-floor.neon` and the three README strings drifting away from the manifest.
 * That is what this test is for.
 *
 * Its assertions are `toContain` on file text, the house style of `FrozenTest`. They
 * pin the number, not the behaviour: a matrix that keeps the literal and adds an
 * `exclude:` for the floor still passes.
 *
 * The export test asks `git archive` what the tarball holds instead of reading
 * `.gitattributes` and inferring. Inference was tried and thrown away: it stayed green
 * while `README.md export-ignore` or `/src export-ignore` emptied the package, and it
 * went red on clean checkouts over a `*.log` pattern, a stray untracked file or an
 * entry in the developer's global ignore file. What it asserts is the top level exactly
 * and, beneath it, that every tracked file still ships — so a `/src/Filament
 * export-ignore` is caught, while a development file someone TRACKS inside `src/` is
 * not: it is in both counts. Two further limits, both deliberate: it reads `HEAD`, not
 * the tag a consumer actually installs, and it is blind to a root file until that file
 * is committed. The old shape saw the uncommitted one; CI sees it on push.
 *
 * It needs a checkout, and fails red without one — the safe direction. Regla de oro 1
 * puts the suite in the container against a bind-mounted repository, and CI checks out
 * with `.git`, so this is not a cost here; it would be one somewhere that copies the
 * tree without `.git`.
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

test('the distribution ships ten top-level entries and every tracked file under them', function (): void {
    $root = escapeshellarg(dirname(__DIR__));
    $tarball = (string) tempnam(sys_get_temp_dir(), 'dist');

    $written = [];
    $status = 1;

    exec(sprintf('git -C %s archive --format=tar --output=%s HEAD', $root, escapeshellarg($tarball)), $written, $status);

    expect($status)->toBe(0);

    $entries = [];
    $status = 1;

    exec(sprintf('tar -tf %s', escapeshellarg($tarball)), $entries, $status);

    expect($status)->toBe(0);

    unlink($tarball);

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

    foreach (['config', 'lang', 'resources', 'src'] as $directory) {
        $tracked = [];
        $status = 1;

        exec(sprintf('git -C %s ls-files %s', $root, escapeshellarg($directory)), $tracked, $status);

        expect($status)->toBe(0);

        $shipped = array_filter(
            $entries,
            static fn (string $entry): bool => str_starts_with($entry, $directory.'/') && ! str_ends_with($entry, '/'),
        );

        expect($shipped)->toHaveSameSize($tracked);
    }
});
