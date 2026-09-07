<?php

/**
 * The `preg_match` calls below are assigned before they are asserted on: Rector
 * rewrites `expect(preg_match(...))->toBe(1)` into `expect($subject)->toMatch(...)`
 * and takes the captures with it. Both the floor test and the ceiling test depend
 * on the captures surviving that.
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
 * The ceiling test is the floor test's mirror, and drifts the other way: silently,
 * because nothing compared these four files to anything before it. `compose.yaml`'s
 * build arg is the manifest, not `docker/Dockerfile`'s `ARG` default —
 * `make build` is `docker compose build php`, and compose hands its own value to the
 * build as the arg, shadowing the Dockerfile default on every path this project
 * actually takes; the default only fires for a bare `docker build` nobody runs here.
 * AGENTS.md §6.35 names the failure this test exists to catch: `phpstan.neon` carries
 * no `phpVersion` on purpose, so that half of the gate analyses at the runtime rather
 * than a pinned number, and the runtime is the dev image. Let the image drift from
 * the workflow's `setup-php` version and that half silently stops seeing the newer
 * version's deprecations — the floor run keeps passing regardless, because it always
 * pins `80400` and never reads the image at all. The absence is what protects the
 * mechanism, so it is the assertion that matters, checked as the literal
 * `phpVersion:`, colon included: the file's own comment names `phpVersion` in prose
 * to explain the absence, and a bare `not->toContain('phpVersion')` would already be
 * red, failed by the sentence that documents the very thing it is meant to confirm.
 * The two cache-key lines in `quality.yml` read the same text, `php8.5-`, so each is
 * asserted with its own line prefix (`key:` / `restore-keys:`) — a single check
 * against the bare version number would pass with one of the two gone stale, because
 * the other still contains it.
 *
 * AGENTS.md §6.24 records three plugin methods that do not exist shipping twice in
 * the README because nothing read it against anything real. The recovery-recipe test
 * below does not fix that generally — it only reaches the one recipe a sibling test
 * already runs, `AssignRoleCommandTest`'s 'the recipe the readme prints opens the
 * panel door it promises to open' — and it is deliberately asymmetric about it. The
 * command's registered name (`getName()`, never a typed-out literal) and the argument
 * order the command's own definition declares (`role` then `authority`) are checked
 * against the command itself, so those two go red the moment the README and the code
 * disagree. The `Warden::allow($role)->everything();` line is weaker than that and the
 * difference is worth naming: it is compared against a second literal typed here, and
 * what makes it worth typing is that it is byte-identical to the line
 * `AssignRoleCommandTest.php` executes — so a README that drifts away from it leaves
 * the sibling test demonstrating a recipe the README no longer prints. That is a
 * transitive guarantee, not a direct one. `Warden::role(['name' =>
 * 'super-admin']);` and `$role->save();` are not checked that way: the suite reaches
 * role creation through the `makeRole()` helper, never through those two lines
 * verbatim, so nothing here can do more for them than notice the README still prints
 * that text. A future reader dropping the `getName()`/argument-order half of the test
 * loses the only two assertions in this file that check the README against the
 * package's own definition of the command, rather than against a second literal
 * typed by hand.
 *
 * The upgrade-tag test exists because following the sentence it guards did not work.
 * Warden registers the CREATE migration under `warden-migrations` and the UPGRADE one
 * under `warden-migrations-v2`, and this package's own upgrade note, plus the audit's
 * own pre-flight line in both languages, all named the first. `create_warden_tables`
 * has no `hasTable` guard on its `Schema::create()` calls, so an installation that
 * already has warden's tables — every installation this note is written for — gets a
 * failed `migrate` and no `identity_key`. Measured against a real consumer on the day
 * `2.0.1` shipped. The tag is read off warden's provider rather than typed a second
 * time, so the day warden renames it the three places that print it go red together.
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

use ElPandaPe\FilamentWarden\Console\AssignRoleCommand;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Events\PermissionGranted;
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

test('the PHP ceiling is the same number everywhere that states it, and phpstan.neon never pins one', function (): void {
    $root = dirname(__DIR__);

    $compose = (string) file_get_contents($root.'/compose.yaml');
    $matched = preg_match('/PHP_VERSION: "(\d+)\.(\d+)"/', $compose, $parts);

    expect($matched)->toBe(1);

    /** @var array{non-falsy-string, numeric-string, numeric-string} $parts */
    $ceiling = $parts[1].'.'.$parts[2];

    expect((string) file_get_contents($root.'/docker/Dockerfile'))
        ->toContain(sprintf('ARG PHP_VERSION=%s', $ceiling))
        ->and((string) file_get_contents($root.'/.github/workflows/quality.yml'))
        ->toContain(sprintf("php-version: '%s'", $ceiling))
        ->toContain(sprintf('key: analysers-${{ runner.os }}-php%s-', $ceiling))
        ->toContain(sprintf('restore-keys: analysers-${{ runner.os }}-php%s-', $ceiling))
        ->and((string) file_get_contents($root.'/.github/workflows/run-tests.yml'))
        ->toContain(sprintf("'%s']", $ceiling))
        ->and((string) file_get_contents($root.'/phpstan.neon'))
        ->not->toContain('phpVersion:');
});

test('the recovery recipe in the readme names the real command, in the order and shape it declares', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__).'/README.md');
    $assign = app(AssignRoleCommand::class);

    expect($readme)->toContain('Warden::allow($role)->everything();')
        ->and(array_keys($assign->getDefinition()->getArguments()))->toBe(['role', 'authority'])
        ->and($readme)->toContain(sprintf('php artisan %s super-admin "App\Models\User:1"', $assign->getName()));
});

test('the publish tag the upgrade tells people to run is the one that ships the upgrade', function (): void {
    $root = dirname(__DIR__);

    $provider = (string) file_get_contents($root.'/vendor/elpandape/warden/src/WardenServiceProvider.php');

    $upgrade = preg_match(
        "/upgrade_warden_to_v2\.php\.stub.*?\], '([a-z0-9-]+)'/s",
        $provider,
        $parts,
    );

    expect($upgrade)->toBe(1);

    /** @var array{non-falsy-string, non-falsy-string} $parts */
    $tag = $parts[1];

    expect((string) file_get_contents($root.'/README.md'))
        ->toContain(sprintf('php artisan vendor:publish --tag=%s', $tag))
        ->and((string) file_get_contents($root.'/lang/en/ui.php'))
        ->toContain(sprintf('--tag=%s`', $tag))
        ->and((string) file_get_contents($root.'/lang/es/ui.php'))
        ->toContain(sprintf('--tag=%s`', $tag));
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

test('the two things the README tells an application to read after a save are there to read', function (): void {
    // Nothing in this package fires a save event of its own, and the README
    // says why. The two mechanisms it offers instead are somebody else's — one
    // warden's, one the container's — so a silent rename upstream or here would
    // leave a recipe that no longer runs, and no gate would see it: the README
    // is prose that nothing else in this suite reads.
    expect(property_exists(PermissionGranted::class, 'actor'))->toBeTrue();

    $named = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(SaveReport::class)->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($named)->toBe(['written', 'preserved', 'refused', 'unresolved', 'granted', 'forbidden', 'revoked', 'lapsed', 'impossible']);
});
