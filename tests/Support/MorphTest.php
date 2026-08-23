<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;

pest()->extend(TestCase::class);

test('a morph alias answers with the class it names', function (): void {
    Relation::morphMap(['post' => Post::class]);

    expect(Morph::model('post'))->toBe(Post::class);
});

test('a class names itself, because an installation may not use a map at all', function (): void {
    expect(Morph::model(Post::class))->toBe(Post::class);
});

test('what the column holds and is not an entity answers nothing', function (mixed $type): void {
    expect(Morph::model($type))->toBeNull();
})->with([
    ['*'],
    ['page:App\\Filament\\Pages\\Settings'],
    ['App\\Models\\ThatWasRenamed'],
    [''],
    [null],
    [42],
]);
