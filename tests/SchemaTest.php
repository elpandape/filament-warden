<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use Illuminate\Support\Facades\Schema;

pest()->extend(TestCase::class);

test('the four tables warden needs are raised for the suite', function (): void {
    expect(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('grants'))->toBeTrue()
        ->and(Schema::hasTable('assigned_roles'))->toBeTrue();
});

test('a permission carries the columns the screens are going to read', function (): void {
    expect(Schema::hasColumns('permissions', ['name', 'title', 'entity_type', 'entity_id', 'only_owned', 'options', 'scope']))
        ->toBeTrue();
});

test('a grant carries its forbidden flag', function (): void {
    expect(Schema::hasColumns('grants', ['permission_id', 'entity_type', 'entity_id', 'forbidden', 'scope']))
        ->toBeTrue();
});

test('warden writes through those tables and answers from them', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('view', User::class);

    expect($user->can('view', User::class))->toBeTrue()
        ->and(Permission::query()->where('name', 'view')->exists())->toBeTrue()
        ->and(Grant::query()->count())->toBe(1);
});

test('an explicit denial beats a grant, which is the whole point of the store', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('view', User::class);
    Warden::forbid($user)->to('view', User::class);

    expect($user->can('view', User::class))->toBeFalse();
});
