<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Vault;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\GrantingPermission;
use ElPandaPe\Warden\Events\PermissionGranted;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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

/**
 * The catalogue with a model whose policy declares `manage` itself.
 *
 * Declared through `catalog.models` rather than a resource: that is the route
 * an application uses for a model with a policy and no screen, and it keeps
 * `VaultPolicy` out of every other catalogue in this file.
 */
function vaultCatalog(): Catalog
{
    config()->set('filament-warden.catalog.models', [Vault::class]);

    return Catalog::for(Panel::make()->id('scratch'));
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

test('a policy action named manage is a cell of its own, not the wildcard', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    RoleGrants::apply($role, vaultCatalog(), [Vault::class => ['manage' => 'granted']]);

    expect(grantCount())->toBe(1)
        ->and(Access::granted($user, 'manage', Vault::class))->toBeTrue()
        ->and(Access::granted($user, 'viewAny', Vault::class))->toBeFalse()
        ->and(RoleGrants::of($role, vaultCatalog())->stances[Vault::class]['manage'])->toBe('granted');
});

test('the wildcard cell of that same row is a second cell, and reaches the whole row', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    RoleGrants::apply($role, vaultCatalog(), [Vault::class => [StateKey::MANAGE => 'granted']]);

    expect(grantCount())->toBe(1)
        ->and(Access::granted($user, 'manage', Vault::class))->toBeTrue()
        ->and(Access::granted($user, 'viewAny', Vault::class))->toBeTrue()
        ->and(RoleGrants::of($role, vaultCatalog())->stances[Vault::class][StateKey::MANAGE])->toBe('granted');
});

test('the two of them can be written at once, and they are two rows', function (): void {
    $role = makeRole();
    $catalog = vaultCatalog();

    RoleGrants::apply($role, $catalog, [Vault::class => ['manage' => 'granted', StateKey::MANAGE => 'forbidden']]);

    $stances = RoleGrants::of($role, $catalog)->stances;

    expect(grantCount())->toBe(2)
        ->and($stances[Vault::class]['manage'])->toBe('granted')
        ->and($stances[Vault::class][StateKey::MANAGE])->toBe('forbidden');
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

test('a permission carrying conditions is shown as narrowed', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $state = RoleGrants::of($role, $catalog);

    expect($state->narrowed()[Post::class]['update'])->toBeTrue()
        ->and($state->locked())->toBeEmpty()
        ->and($state->narrowings[Post::class]['update']->shape)->toBe(Shape::Conditions);
});

test('a narrowed cell nobody touched is not rewritten', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $state = RoleGrants::of($role, $catalog);
    $narrowings = [Post::class => ['update' => $state->narrowings[Post::class]['update']->toPayload()]];

    expect(RoleGrants::changes($role, $catalog, $state->stances, $narrowings))->toBeEmpty();
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

/**
 * @return array{mode: string, rules: list<array<string, string>>}
 */
function conditionOn(string $column, string $value, string $logic = 'and'): array
{
    return ['mode' => 'conditions', 'rules' => [
        ['logic' => $logic, 'kind' => 'value', 'column' => $column, 'operator' => '=', 'value' => $value],
    ]];
}

/**
 * @param  array<string, array<string, mixed>>  $narrowings
 */
function saveUpdateOnPosts(Model $role, array $narrowings): void
{
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['update' => 'granted']], $narrowings);
}

test('a condition written from the grid leaves one grant and one twin', function (): void {
    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    $narrowing = RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update'];

    expect($narrowing->shape)->toBe(Shape::Conditions)
        ->and($narrowing->rules[0]->value)->toBe('alpha')
        ->and(grantCount())->toBe(1);
});

test('changing a condition stops the old one authorizing, which a fresh grant would not', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    $alpha = Post::query()->create(['title' => 'alpha']);
    $beta = Post::query()->create(['title' => 'beta']);

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);
    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'beta')]]);

    expect(Access::granted($user, 'update', $beta))->toBeTrue()
        ->and(Access::granted($user, 'update', $alpha))->toBeFalse()
        ->and(grantCount())->toBe(1);
});

test('widening a narrowed cell answers for every record, and keeps no twin grant', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    $alpha = Post::query()->create(['title' => 'alpha']);
    $beta = Post::query()->create(['title' => 'beta']);

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);
    saveUpdateOnPosts($role, []);

    expect(Access::granted($user, 'update', $beta))->toBeTrue()
        ->and(Access::granted($user, 'update', $alpha))->toBeTrue()
        ->and(RoleGrants::of($role, gridCatalog())->narrowed())->toBeEmpty()
        ->and(grantCount())->toBe(1);
});

test('leaving ownership behind takes its grant with it, though the revokes are disjoint', function (): void {
    $role = makeRole();

    Warden::ownedVia(Post::class, 'title');

    saveUpdateOnPosts($role, [Post::class => ['update' => ['mode' => 'owned', 'rules' => []]]]);

    expect(RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update']->shape)->toBe(Shape::Owned);

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    expect(RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update']->shape)->toBe(Shape::Conditions)
        ->and(grantCount())->toBe(1);
});

test('taking a narrowed cell away leaves nothing behind at all', function (): void {
    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    RoleGrants::apply($role, gridCatalog(), [], []);

    expect(grantCount())->toBe(0);
});

test('a denial can be narrowed too, and it is written as a denial', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    $alpha = Post::query()->create(['title' => 'alpha']);

    Warden::allow($role)->to('update', Post::class);

    RoleGrants::apply(
        $role,
        gridCatalog(),
        [Post::class => ['update' => 'forbidden']],
        [Post::class => ['update' => conditionOn('title', 'alpha')]],
    );

    expect(Access::granted($user, 'update', $alpha))->toBeFalse()
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class]['update'])->toBe('forbidden');
});

test('a rule the table cannot hold leaves the store exactly as it was', function (): void {
    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    $before = RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update'];

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('nope', 'alpha')]]);

    $after = RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update'];

    expect($after->is($before))->toBeTrue()
        ->and(grantCount())->toBe(1);
});

test('two rows for one cell are shown, said out loud, and never written over', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('title', 'alpha');
    Warden::allow($role)->to('update', Post::class);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->narrowings[Post::class]['update']->shape)->toBe(Shape::Tangled)
        ->and($state->locked()[Post::class]['update'])->toBeTrue()
        ->and(RoleGrants::changes($role, gridCatalog(), [], []))->toBeEmpty();
});

test('an application that vetoes the grant is answered, not argued with', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(GrantingPermission::class, static fn (): bool => false);

    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    expect(grantCount())->toBe(0);
});

test('the whole grid is written inside one transaction', function (): void {
    $levels = [];
    $outside = DB::transactionLevel();

    Event::listen(PermissionGranted::class, static function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$outside + 1]);
});

test('a door written from the grid is titled by this package, not by a capital letter', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $door = 'page:'.Reports::class;

    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    $permission = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', $door)
        ->firstOrFail();

    expect($permission->getAttribute('title'))->toBe('Access Reports');
});

test('a title somebody wrote by hand is theirs, and stays', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $door = 'page:'.Reports::class;

    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', $door)
        ->update(['title' => 'The reporting screen']);

    RoleGrants::apply($role, $catalog, []);
    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    $title = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', $door)
        ->value('title');

    expect($title)->toBe('The reporting screen');
});

test('an action over a model is left to warden, which titles it well already', function (): void {
    $role = makeRole();

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']]);

    $permission = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'viewAny')
        ->firstOrFail();

    expect($permission->getAttribute('title'))->toBe('ViewAny posts');
});

test('a loose name the application declared is left to warden, whose title is fine for it', function (): void {
    config()->set('filament-warden.catalog.custom', ['export-reports' => 'read']);

    $role = makeRole();

    RoleGrants::apply($role, gridCatalog(), ['export-reports' => [StateKey::DOOR => 'granted']]);

    $title = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'export-reports')
        ->value('title');

    expect($title)->toBe('Export reports');
});

test('a grant that belongs to another tenant is shown, marked and left alone', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    // Written under tenant 7, read from tenant 7's neighbour.
    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', Post::class);
    });

    $neighbour = Warden::tenant()->onceTo(8, static fn (): RoleState => RoleGrants::of($role, gridCatalog()));

    expect($neighbour instanceof RoleState ? $neighbour->stances : ['unread'])->toBeEmpty();

    // With no tenant active warden answers with every tenant's rows, so the cell
    // is there — and it is not this screen's to write.
    $state = RoleGrants::of($role, $catalog);

    expect($state->stances[Post::class]['viewAny'])->toBe('granted')
        ->and($state->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::Elsewhere)
        ->and($state->locked()[Post::class]['viewAny'])->toBeTrue();
});

test('switching off a cell that belongs to another tenant writes nothing, rather than saying it did', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', Post::class);
    });

    $before = grantCount();

    RoleGrants::apply($role, $catalog, []);

    expect(grantCount())->toBe($before)
        ->and(RoleGrants::of($role, $catalog)->stances[Post::class]['viewAny'])->toBe('granted');
});

test('a grant of this tenant is writable, and one of no tenant is too when there is none', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['viewAny']->shape)
        ->toBe(Shape::All);
});

test('a role grant kept global by configuration is writable under a tenant', function (): void {
    Warden::tenant()->dontScopeRoleGrants();

    $role = makeRole();
    $catalog = gridCatalog();

    Warden::tenant()->onceTo(7, static function () use ($role, $catalog): void {
        Warden::allow($role)->to('viewAny', Post::class);

        $state = RoleGrants::of($role, $catalog);

        expect($state->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::All)
            ->and($state->locked())->toBeEmpty()
            ->and(RoleGrants::changes($role, $catalog, []))->toHaveCount(1);
    });
});

test('a title an older version of this package wrote is corrected, and a persons is not', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $door = 'page:'.Reports::class;
    $class = Context::resolve()->permissionClass();

    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    // What v0.9.1 wrote: the screen's name, with no verb.
    $class::query()->withoutGlobalScopes()->where('name', $door)->update(['title' => 'Reports']);

    RoleGrants::apply($role, $catalog, []);
    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    expect($class::query()->withoutGlobalScopes()->where('name', $door)->value('title'))->toBe('Access Reports');
});

test('a title the version before this one wrote is corrected too, and the list only grows', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $door = 'page:'.Reports::class;
    $class = Context::resolve()->permissionClass();

    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    foreach (['Page:'.Reports::class, 'Reports', 'Access Reports'] as $older) {
        $class::query()->withoutGlobalScopes()->where('name', $door)->update(['title' => $older]);

        RoleGrants::apply($role, $catalog, []);
        RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

        expect($class::query()->withoutGlobalScopes()->where('name', $door)->value('title'))
            ->toBe('Access Reports', "[{$older}] was not corrected");
    }
});

test('a cell that is both ownership and conditions is shown, said out loud, and never drawn as owned', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->toOwn(Post::class, 'update')->where('title', '=', 'alpha');

    $state = RoleGrants::of($role, $catalog);

    expect($state->stances[Post::class]['update'])->toBe('granted')
        ->and($state->narrowings[Post::class]['update']->shape)->toBe(Shape::Unreadable)
        ->and($state->locked()[Post::class]['update'])->toBeTrue()
        ->and(RoleGrants::changes($role, $catalog, [], []))->toBeEmpty();
});

test('a cell that is both ownership and conditions is never written over', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->toOwn(Post::class, 'update')->where('title', '=', 'alpha');

    $permission = permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'update')
        ->whereNotNull('options')
        ->firstOrFail();

    $before = $permission->getAttribute('options');

    RoleGrants::apply($role, $catalog, [], []);

    expect(grantCount())->toBe(1)
        ->and($permission->refresh()->getAttribute('options'))->toBe($before)
        ->and($permission->getAttribute('only_owned'))->toBeTrue();
});
