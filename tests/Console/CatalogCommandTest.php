<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Support\Facades\Artisan;

pest()->extend(TestCase::class);

/**
 * The `test` panel registers no resource for `Post`, so a grant naming its
 * class is itself undeclared and the row it mints would answer "no"
 * everywhere in the table. `catalog.models` exists for exactly this case — a
 * model with a policy and no resource — and `AuditCommandTest` reaches for
 * the same fix for the same reason.
 *
 * `Artisan::output()` drains its buffer, so it answers once per run. And the
 * console view breaks a long heading on the terminal's width, so a sentence
 * that is one line in the language file is not one line on screen: whitespace
 * is flattened before anything is compared.
 *
 * @param  array<string, mixed>  $arguments
 */
function catalogOutput(array $arguments = []): string
{
    Artisan::call('filament-warden:catalog', $arguments);

    return (string) preg_replace('/\s+/', ' ', Artisan::output());
}

test('it lists what the panels declare', function (): void {
    expect(catalogOutput())->toContain('panel:test');
});

test('it says which entries the store has a row for', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    $output = catalogOutput();

    expect($output)->toContain('viewAny')
        ->and($output)->toContain('yes');
});

test('an entry with no row in the store is marked as missing', function (): void {
    expect(catalogOutput())->toContain('no');
});

test('it can be narrowed to one panel', function (): void {
    expect(catalogOutput(['--panel' => 'bare']))->toContain('panel:bare')
        ->and(catalogOutput(['--panel' => 'bare']))->not->toContain('panel:test');
});

test('an unknown panel is an error and not an empty listing', function (): void {
    expect(Artisan::call('filament-warden:catalog', ['--panel' => 'nope']))->toBe(1);
});
