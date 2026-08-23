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
 * The direction guarded is LOWERING the floor, which is what this version did and is
 * silent in all four — nothing downstream moves, and the analysis, the matrix and the
 * README go on describing the number that left. Raising it is NOT silent: composer
 * aborts on the older runtime before anything else gets a turn. The test earns its
 * place on the quiet direction, not the loud one.
 *
 * The assertions are `toContain` on file text, the house style of `FrozenTest`. They
 * pin the number, not the behaviour: a matrix that keeps the literal and adds an
 * `exclude:` for the floor still passes.
 *
 * The export test reads `.gitignore` rather than asking git what is tracked, because
 * the only root files git does not carry — `AGENTS.md` and `CLAUDE.md` — are named
 * there, and a suite that shells out to git would depend on a `.git` this package has
 * no right to expect.
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

test('every file at the root is either shipped or export-ignored', function (): void {
    $root = dirname(__DIR__);

    $shipped = ['CHANGELOG.md', 'CONTRIBUTING.md', 'LICENSE.md', 'README.md', 'SECURITY.md', 'composer.json'];

    $untracked = array_map(
        static fn (string $line): string => mb_ltrim(mb_trim($line), '/'),
        explode("\n", (string) file_get_contents($root.'/.gitignore')),
    );

    $attributes = (string) file_get_contents($root.'/.gitattributes');

    /** @var list<string> $entries */
    $entries = scandir($root);

    foreach ($entries as $name) {
        if (! is_file($root.'/'.$name) || in_array($name, $shipped, true) || in_array($name, $untracked, true)) {
            continue;
        }

        expect($attributes)->toContain($name.' export-ignore');
    }
});
