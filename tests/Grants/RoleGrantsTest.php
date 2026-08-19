<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;

pest()->extend(TestCase::class);

function gridCatalog(): Catalog
{
    return Catalog::for(
        Panel::make()->id('scratch')->resources([PostResource::class])->pages([Reports::class]),
    );
}

function grantCount(): int
{
    return Context::resolve()->grantClass()::query()->count();
}

test('a cell nobody wrote becomes a grant, and the store answers for it', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']]);

    expect(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('granted')
        ->and(Access::granted($user, 'viewAny', Post::class))->toBeTrue();
});

test('a granted cell turned to forbidden leaves one row, not two', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'granted']]);
    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'forbidden']]);

    expect(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, $catalog)->stances[Post::class]['viewAny'])->toBe('forbidden')
        ->and(Access::granted($user, 'viewAny', Post::class))->toBeFalse();
});

test('a forbidden cell turned back to granted leaves one row too', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'forbidden']]);
    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'granted']]);

    expect(grantCount())->toBe(1)
        ->and(Access::granted($user, 'viewAny', Post::class))->toBeTrue();
});

test('a cell returned to abstaining leaves nothing behind', function (string $from): void {
    $role = makeRole();
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => $from]]);
    RoleGrants::apply($role, $catalog, []);

    expect(grantCount())->toBe(0)
        ->and(RoleGrants::of($role, $catalog)->stances)->toBeEmpty();
})->with(['granted', 'forbidden']);

test('shift reaches a denial in one step, and that is a single write', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'forbidden']]);

    expect(grantCount())->toBe(1)
        ->and(Access::granted($user, 'viewAny', Post::class))->toBeFalse();
});

test('the wildcard column reaches every action the policy declares', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    RoleGrants::apply($role, gridCatalog(), [Post::class => [StateKey::MANAGE => 'granted']]);

    expect(Access::granted($user, 'viewAny', Post::class))->toBeTrue()
        ->and(Access::granted($user, 'delete', Post::class))->toBeTrue()
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class][StateKey::MANAGE])->toBe('granted');
});

test('a door is written with no entity at all', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);
    $door = 'page:'.Reports::class;

    RoleGrants::apply($role, gridCatalog(), [$door => [StateKey::DOOR => 'granted']]);

    expect(Access::granted($user, $door))->toBeTrue()
        ->and(RoleGrants::of($role, gridCatalog())->stances[$door][StateKey::DOOR])->toBe('granted');
});

test('a cell that did not change is not written again', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $state = [Post::class => ['viewAny' => 'granted']];

    RoleGrants::apply($role, $catalog, $state);

    expect(RoleGrants::changes($role, $catalog, $state))->toBeEmpty();
});

test('a permission carrying conditions is shown as narrowed and survives a save', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $before = RoleGrants::of($role, $catalog);

    expect($before->narrowed[Post::class]['update'])->toBeTrue();

    RoleGrants::apply($role, $catalog, []);

    expect(RoleGrants::of($role, $catalog)->stances[Post::class]['update'])->toBe('granted');
});

test('a grant over one record is not a cell of the grid', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post);

    expect(RoleGrants::of($role, gridCatalog())->stances)->toBeEmpty();
});

test('a role granted everything does not confuse the grid', function (): void {
    $role = makeRole('super-admin');

    Warden::allow($role)->everything();

    expect(RoleGrants::of($role, gridCatalog())->stances)->toBeEmpty();
});

test('a permission over a model the panel does not show is left out of the grid', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', User::class);

    expect(RoleGrants::of($role, gridCatalog())->stances)->toBeEmpty();
});

test('a rule written over every entity is reported, not swallowed', function (): void {
    $role = makeRole('super-admin');

    Warden::allow($role)->everything();

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->stances)->toBeEmpty()
        ->and($state->wider)->toBe(['*' => 'granted']);
});

test('an action written over every entity is reported by its own name', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('view', '*');

    expect(RoleGrants::of($role, gridCatalog())->wider)->toBe(['view' => 'granted']);
});

test('a forbidden wider rule wins over a granted one of the same name', function (): void {
    $role = makeRole();

    Warden::allow($role)->everything();
    Warden::forbid($role)->everything();

    expect(RoleGrants::of($role, gridCatalog())->wider)->toBe(['*' => 'forbidden']);
});
