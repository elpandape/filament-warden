<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Abilities;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('the actions of a model are the methods its policy declares, in the order it declares them', function (): void {
    expect(Abilities::of(Post::class))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('a policy that declares two actions contributes two, not the twelve filament can ask for', function (): void {
    expect(Abilities::of(Tag::class))->toBe(['viewAny', 'view']);
});

test('a model with no policy contributes nothing at all', function (): void {
    expect(Abilities::of(Comment::class))->toBeEmpty();
});

test('a gate hook and the helpers a policy inherits are not actions', function (): void {
    expect(Abilities::of(Post::class))
        ->not->toContain('before')
        ->not->toContain('denyWithStatus')
        ->not->toContain('denyAsNotFound')
        ->not->toContain('label');
});
