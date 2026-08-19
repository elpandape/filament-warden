<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\CommentResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Panel;

pest()->extend(TestCase::class);

/**
 * @param  list<Entry>  $entries
 * @return list<string>
 */
function namesFor(array $entries, ?string $model): array
{
    return array_values(array_map(
        static fn (Entry $entry): string => $entry->name,
        array_filter($entries, static fn (Entry $entry): bool => $entry->model === $model),
    ));
}

/**
 * @param  list<Entry>  $entries
 * @return list<Entry>
 */
function entriesFrom(array $entries, Origin $origin): array
{
    return array_values(array_filter($entries, static fn (Entry $entry): bool => $entry->origin === $origin));
}

test('a resource contributes exactly what its model policy declares', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(namesFor($catalog->entries, Post::class))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('a resource whose model has no policy contributes nothing, and says nothing about it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([CommentResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Resource))->toBeEmpty();
});

test('a permission derived from a policy carries the morph alias the store will write', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    $entry = entriesFrom($catalog->entries, Origin::Resource)[0];

    expect($entry->entityType)->toBe(new Post()->getMorphClass())
        ->and($entry->model)->toBe(Post::class)
        ->and($entry->source)->toBe(PostResource::class);
});

test('each action lands in the scope the map gives it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    $scopes = [];

    foreach ($catalog->entries as $entry) {
        if ($entry->model === Post::class) {
            $scopes[$entry->name] = $entry->scope;
        }
    }

    expect($scopes)->toBe([
        'viewAny' => Scope::Read,
        'view' => Scope::Read,
        'create' => Scope::Write,
        'update' => Scope::Write,
        'delete' => Scope::Withdraw,
        'deleteAny' => Scope::Withdraw,
    ]);
});

test('a page is a loose permission with no entity, because a page is not a model', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->pages([Reports::class]));

    $page = entriesFrom($catalog->entries, Origin::Page)[0];

    expect($page->name)->toBe('page:'.Reports::class)
        ->and($page->entityType)->toBeNull()
        ->and($page->model)->toBeNull()
        ->and($page->scope)->toBe(Scope::Read)
        ->and($page->source)->toBe(Reports::class);
});

test('a widget is a loose permission too', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->widgets([Summary::class]));

    $widget = entriesFrom($catalog->entries, Origin::Widget)[0];

    expect($widget->name)->toBe('widget:'.Summary::class)
        ->and($widget->entityType)->toBeNull()
        ->and($widget->scope)->toBe(Scope::Read);
});

test('a widget registered through its configuration is catalogued by its class', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->widgets([Summary::make()]));

    expect(namesFor($catalog->entries, null))->toContain('widget:'.Summary::class);
});

test('a widget a resource brings with it is catalogued as well', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Widget))->toHaveCount(1)
        ->and(entriesFrom($catalog->entries, Origin::Widget)[0]->name)->toBe('widget:'.Summary::class);
});

test('the door of the panel is in the catalogue, or nobody could ever be given it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $door = entriesFrom($catalog->entries, Origin::Panel)[0];

    expect($door->name)->toBe('panel:scratch')
        ->and($door->entityType)->toBeNull()
        ->and($door->scope)->toBe(Scope::Read);
});

test('the two models this package owns are in the catalogue before it owns a screen', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    expect(namesFor($catalog->entries, roleClass()))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'])
        ->and(namesFor($catalog->entries, permissionClass()))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('the two models this package owns carry the morph alias warden writes for them', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $role = entriesFrom($catalog->entries, Origin::Model)[0];

    expect($role->entityType)->toBe('warden.role')
        ->and($role->source)->toBeNull();
});

test('a model an application declares by config needs no resource to be catalogued', function (): void {
    config()->set('filament-warden.catalog.models', [Tag::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch'));

    expect(namesFor($catalog->entries, Tag::class))->toBe(['viewAny', 'view']);
});

test('a loose permission an application declares arrives with the scope it declared', function (): void {
    config()->set('filament-warden.catalog.custom', [
        'export-reports' => 'read',
        'close-month' => 'nonsense',
    ]);

    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $custom = [];

    foreach (entriesFrom($catalog->entries, Origin::Custom) as $entry) {
        $custom[$entry->name] = $entry->scope;
    }

    expect($custom)->toBe([
        'export-reports' => Scope::Read,
        'close-month' => Scope::Write,
    ]);
});

test('the same permission reached twice is catalogued once', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(namesFor($catalog->entries, Post::class))->toHaveCount(6);
});

test('a resource wins the deduplication, so an entry knows the screen it belongs to', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Resource))->toHaveCount(6)
        ->and(namesFor(entriesFrom($catalog->entries, Origin::Model), Post::class))->toBeEmpty();
});
