<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('an entity is filed under its model, and its actions under their own names', function (): void {
    $entry = new Entry('viewAny', 'post', Post::class, Scope::Read, Origin::Resource);

    expect(StateKey::row($entry))->toBe(Post::class);
});

test('a door is filed under its permission name, and has exactly one action', function (): void {
    $entry = new Entry('page:App\Filament\Pages\Reports', null, null, Scope::Read, Origin::Page);

    expect(StateKey::row($entry))->toBe('page:App\Filament\Pages\Reports');
});

test('a permission name with a dot stops the grid instead of breaking livewire', function (): void {
    $entry = new Entry('reports.export', null, null, Scope::Read, Origin::Custom);

    expect(fn (): string => StateKey::row($entry))
        ->toThrow(LogicException::class, 'The permission [reports.export] cannot be shown on the grid');
});

test('an action name with a dot stops it too, on the path the grid really takes', function (): void {
    // The row and the column are guarded by one method now: the grid keys a
    // column off the catalogue's action string, never off an Entry, so a guard
    // that only an Entry could reach left that half open.
    expect(fn (): string => StateKey::of('view.any'))->toThrow(LogicException::class)
        ->and(StateKey::of('viewAny'))->toBe('viewAny');
});

test('the wildcard column is filed under the name warden stores, which no policy can declare', function (): void {
    expect(StateKey::MANAGE)->toBe('*');
});
