<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('an entry is an action over an entity, and carries where it came from', function (): void {
    $entry = new Entry(
        name: 'viewAny',
        entityType: 'warden.role',
        model: Post::class,
        scope: Scope::Read,
        origin: Origin::Resource,
        source: Post::class,
    );

    expect($entry->name)->toBe('viewAny')
        ->and($entry->entityType)->toBe('warden.role')
        ->and($entry->model)->toBe(Post::class)
        ->and($entry->scope)->toBe(Scope::Read)
        ->and($entry->origin)->toBe(Origin::Resource)
        ->and($entry->source)->toBe(Post::class);
});

test('the same action over the same entity is the same permission, whichever screen derived it', function (): void {
    $fromResource = new Entry('viewAny', 'warden.role', Post::class, Scope::Read, Origin::Resource, Post::class);
    $fromConfig = new Entry('viewAny', 'warden.role', Post::class, Scope::Read, Origin::Model);

    expect($fromResource->key())->toBe($fromConfig->key());
});

test('the same action over another entity is another permission', function (): void {
    $role = new Entry('viewAny', 'warden.role', Post::class, Scope::Read, Origin::Resource);
    $permission = new Entry('viewAny', 'warden.permission', Post::class, Scope::Read, Origin::Resource);

    expect($role->key())->not->toBe($permission->key());
});

test('a loose permission has no entity half at all', function (): void {
    $page = new Entry('page:App\Filament\Pages\Reports', null, null, Scope::Read, Origin::Page);

    expect($page->key())->toBe('page:App\Filament\Pages\Reports|');
});
