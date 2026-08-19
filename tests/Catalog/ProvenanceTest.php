<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Provenance;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class);

function panelCatalog(): Catalog
{
    return Catalog::for(
        Panel::make()->id('scratch')->resources([PostResource::class])->pages([Reports::class]),
    );
}

/**
 * The row warden would write, without going through a grant.
 */
function catalogRow(string $name, ?string $entityType = null): Model
{
    $permission = Warden::permission(['name' => $name, 'entity_type' => $entityType]);
    $permission->save();

    return $permission;
}

test('a permission a policy declares says so', function (): void {
    $row = catalogRow('viewAny', new Post()->getMorphClass());

    expect(Provenance::of($row, panelCatalog()))->toBe(Provenance::Policy);
});

test('a door is loose, because no policy declares a page', function (): void {
    $row = catalogRow('page:'.Reports::class);

    expect(Provenance::of($row, panelCatalog()))->toBe(Provenance::Loose);
});

test('a permission an installation declared by hand is loose too', function (): void {
    config()->set('filament-warden.catalog.custom', ['export-reports' => 'read']);

    expect(Provenance::of(catalogRow('export-reports'), panelCatalog()))->toBe(Provenance::Loose);
});

test('the door of the panel is loose', function (): void {
    expect(Provenance::of(catalogRow('panel:scratch'), panelCatalog()))->toBe(Provenance::Loose);
});

test('a rule wider than one action over one entity is a wildcard, on either side', function (string $name, ?string $type): void {
    expect(Provenance::of(catalogRow($name, $type), panelCatalog()))->toBe(Provenance::Wildcard);
})->with([
    'everything' => ['*', '*'],
    'manage one entity' => ['*', 'post'],
    'one action over everything' => ['view', '*'],
]);

test('a permission nothing declares is the silent mistake, and it is named', function (): void {
    $row = catalogRow('viwAny', new Post()->getMorphClass());

    expect(Provenance::of($row, panelCatalog()))->toBe(Provenance::Unknown)
        ->and(Provenance::Unknown->isDeclared())->toBeFalse();
});

test('an action over a model with no policy is not declared either', function (): void {
    $row = catalogRow('viewAny', new Comment()->getMorphClass());

    expect(Provenance::of($row, panelCatalog()))->toBe(Provenance::Unknown);
});

test('every provenance but the unknown one is declared', function (): void {
    foreach ([Provenance::Wildcard, Provenance::Policy, Provenance::Loose] as $provenance) {
        expect($provenance->isDeclared())->toBeTrue();
    }
});

test('a row with no name at all is not declared', function (): void {
    $permission = new (Context::resolve()->permissionClass())();

    expect(Provenance::of($permission, panelCatalog()))->toBe(Provenance::Unknown);
});

test('a permission carrying conditions keeps the provenance of the row it twins', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class)->where('title', 'a');

    $twin = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('options')
        ->firstOrFail();

    expect(Provenance::of($twin, panelCatalog()))->toBe(Provenance::Policy);
});

test('the badge and the filter never disagree, whatever is in the catalogue', function (): void {
    config()->set('filament-warden.catalog.custom', ['export-reports' => 'read']);

    catalogRow('viewAny', new Post()->getMorphClass());
    catalogRow('page:'.Reports::class);
    catalogRow('export-reports');
    catalogRow('panel:scratch');
    catalogRow('*', '*');
    catalogRow('view', '*');
    catalogRow('*', 'post');
    catalogRow('viwAny', new Post()->getMorphClass());
    catalogRow('viewAny', 'gone.away');

    $catalog = panelCatalog();
    $class = Context::resolve()->permissionClass();

    foreach (Provenance::cases() as $provenance) {
        $filtered = $class::query()
            ->withoutGlobalScopes()
            ->where(fn (Illuminate\Database\Eloquent\Builder $query) => $provenance->applyTo($query, $catalog))
            ->pluck('id')
            ->all();

        $badged = $class::query()
            ->withoutGlobalScopes()
            ->get()
            ->filter(fn (Model $row): bool => Provenance::of($row, $catalog) === $provenance)
            ->pluck('id')
            ->all();

        sort($filtered);
        sort($badged);

        expect($filtered)->toBe($badged, "[{$provenance->value}] the filter and the badge disagree");
    }
});
