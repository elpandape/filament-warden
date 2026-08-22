<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Broken;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

pest()->extend(TestCase::class);

test('the columns a condition may compare are the columns of the table', function (): void {
    expect(Columns::of(Post::class))->toBe(['id', 'title', 'published', 'created_at', 'updated_at']);
});

test('the answer is remembered, because nothing in laravel remembers it', function (): void {
    Columns::of(Post::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Columns::of(Post::class);

    expect(DB::getQueryLog())->toBeEmpty();
});

test('a connection that will not answer leaves nothing to compare', function (): void {
    expect(Columns::of(Broken::class))->toBeEmpty();
});

test('what is remembered can be forgotten', function (): void {
    Columns::of(Post::class);
    Columns::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();

    Columns::of(Post::class);

    expect(DB::getQueryLog())->not->toBeEmpty();
});

test('the account a condition compares against is the one the panel authenticates', function (): void {
    expect(Columns::authority())->toContain('email');
});

test('a provider that does not resolve eloquent models offers no columns', function (): void {
    config()->set('auth.providers.users', ['driver' => 'database', 'table' => 'users']);
    Auth::forgetGuards();

    expect(Columns::authority())->toBeEmpty();
});

test('a provider pointing at something that is not a model offers none either', function (): void {
    config()->set('auth.providers.users.model', stdClass::class);
    Auth::forgetGuards();

    expect(Columns::authority())->toBeEmpty();
});

test('a model says which of its columns come back as booleans', function (): void {
    expect(Columns::booleans(Post::class))->toBe(['published']);
});

test('a model that casts nothing to boolean answers with an empty list', function (): void {
    expect(Columns::booleans(Comment::class))->toBeEmpty();
});

test('the columns and the booleans of one model cost one look at the schema', function (): void {
    Columns::forget();

    DB::flushQueryLog();
    DB::enableQueryLog();
    Columns::of(Post::class);
    Columns::booleans(Post::class);
    Columns::of(Post::class);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});
