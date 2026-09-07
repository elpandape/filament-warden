<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use ElPandaPe\FilamentWarden\Catalog\Audit;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Conditions\Shape;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
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
use ElPandaPe\Warden\Support\PermissionIdentity;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * `StateKey::MANAGE` and warden's `'*'` are one value wearing two hats.
 *
 * The read half files a stored `'*'` under `StateKey::MANAGE` because the two
 * ARE the same string; the write half in `RoleGrants::cells()` hands warden
 * `RoleGrants::WILDCARD` under that same key. Only the read half is pinned
 * below — if the two constants ever diverge, that pin goes red first and the
 * write half is what the reader must then go and check, because nothing here
 * names it.
 *
 * `Narrowing::is()` compares `toPayload()`, and both sides of that comparison
 * route every value through `Value::text()` — so it answers "unchanged" for a
 * rule whose type moved as long as its text did not, and it cannot be trusted
 * as the source of what a write becomes. `flipping a stance keeps the rule
 * exactly as the store wrote it`, `a rule the browser really changed is
 * written as the browser sent it`, `a numeric string keeps being a string
 * when only the stance moves` and `a decimal string keeps being a string when
 * only the stance moves` depend on `changes()` picking the value from the
 * stored `Narrowing` when nothing moved, rather than from what `is()` alone
 * could tell apart.
 *
 * `writable()`'s `(string)` cast on both sides was untested: every other test
 * here drives it with two integers. `grants.scope` reads back as a native PHP
 * `int`, so the cast only earns its keep against a tenant resolver handing
 * back a numeric string. There is no matching negative next to `'a row scoped
 * as an integer is still this screen's to write under a string tenant id'`:
 * `held()` queries through warden's own `TenantScope`, so a row stamped for a
 * genuinely different tenant is invisible before `writable()` ever runs —
 * asking for it throws on the missing array key rather than answering
 * `Shape::Elsewhere`. The existing `'a grant that belongs to another tenant
 * is shown, marked and left alone'` already covers a stub `writable()`
 * returning unconditionally true.
 */
pest()->extend(TestCase::class);

function gridCatalog(): Catalog
{
    return Catalog::for(
        Panel::make()->id('scratch')->resources([PostResource::class])->pages([Reports::class]),
    );
}

/**
 * The `scope` warden stamped on the role's one grant row, read past the tenant
 * scope so the answer is the column and not the current tenant's view of it.
 */
function heldGrantScope(Model $role): int|string|null
{
    /** @var int|string|null $scope */
    $scope = Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('entity_id', $role->getKey())
        ->value('scope');

    return $scope;
}

function grantCount(): int
{
    return Context::resolve()->grantClass()::query()->count();
}

function permissionCount(): int
{
    return permissionClass()::query()->withoutGlobalScopes()->count();
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

test('the catalogue row the cell minted survives, and the audit does not go red on it', function (): void {
    $role = makeRole();
    $panel = Panel::make()->id('scratch')->resources([PostResource::class]);
    $catalog = Catalog::for($panel);

    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'granted']]);
    RoleGrants::apply($role, $catalog, []);

    $audit = Audit::of([$panel]);

    expect(grantCount())->toBe(0)
        ->and(permissionCount())->toBe(1)
        ->and($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->toContain('viewAny on '.Post::class);
});

test('the wildcard row the MANAGE cell mints survives, and the audit does not go red on it', function (): void {
    $role = makeRole();
    $panel = Panel::make()->id('scratch')->resources([PostResource::class]);
    $catalog = Catalog::for($panel);

    RoleGrants::apply($role, $catalog, [Post::class => [StateKey::MANAGE => 'granted']]);
    RoleGrants::apply($role, $catalog, []);

    $audit = Audit::of([$panel]);

    expect(grantCount())->toBe(0)
        ->and(permissionCount())->toBe(1)
        ->and($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->toContain('* on '.Post::class);
});

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

test('a wildcard warden wrote itself is filed under the key the grid draws', function (): void {
    $role = makeRole();

    Warden::allow($role)->toManage(Post::class);

    $stances = RoleGrants::of($role, gridCatalog())->stances;

    expect($stances[Post::class][StateKey::MANAGE] ?? null)->toBe('granted')
        ->and($stances[Post::class]['viewAny'] ?? null)->toBeNull();
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

test('a grant over one record is reported instead of discarded', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post);

    $records = RoleGrants::of($role, gridCatalog())->records;

    expect($records)->toHaveCount(1)
        ->and($records[0]->name)->toBe('view')
        ->and($records[0]->model)->toBe(Post::class)
        ->and($records[0]->id)->toBe(recordKey($post))
        ->and($records[0]->stance)->toBe(Stance::Granted)
        ->and($records[0]->reach())->toBeNull();
});

test('a record grant is never reported as a rule over every entity', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->wider)->toBeEmpty()
        ->and($state->records)->toHaveCount(1);
});

test('a record grant that is a denial is reported as one', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::forbid($role)->to('view', $post);

    $records = RoleGrants::of($role, gridCatalog())->records;

    expect($records)->toHaveCount(1)
        ->and($records[0]->stance)->toBe(Stance::Forbidden);
});

test('a record grant carries the rule written on it', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post)->where('title', '=', 'A post');

    $records = RoleGrants::of($role, gridCatalog())->records;

    expect($records)->toHaveCount(1)
        ->and($records[0]->narrowing->shape)->toBe(Shape::Conditions)
        ->and($records[0]->reach())->toBe('conditions');
});

test('a narrowed record grant survives a save that clears the cell above it', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post)->where('title', '=', 'A post');
    Warden::allow($role)->to('view', Post::class);

    // Clearing the class cell. The row pinned to a record answers no check this
    // grid makes, so this screen neither draws it nor deletes it — and now that
    // a save names twins by their model, forgetting that would take it with it.
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['view' => 'abstain']], []);

    $state = RoleGrants::of($role, gridCatalog());

    // Abstaining is the absence of a row, so the cell is gone from the map
    // rather than set to a word.
    expect($state->stances[Post::class]['view'] ?? null)->toBeNull()
        ->and($state->records)->toHaveCount(1)
        ->and($state->records[0]->narrowing->shape)->toBe(Shape::Conditions)
        ->and(grantCount())->toBe(1);
});

test('a record grant over a model the panel does not show is left out', function (): void {
    $role = makeRole();
    $user = makeUser();

    Warden::allow($role)->to('view', $user);

    expect(RoleGrants::of($role, gridCatalog())->records)->toBeEmpty();
});

test('a record grant written under another tenant is marked, not offered', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::tenant()->onceTo(7, static function () use ($role, $post): void {
        Warden::allow($role)->to('view', $post);
    });

    $records = RoleGrants::of($role, gridCatalog())->records;

    expect($records)->toHaveCount(1)
        ->and($records[0]->narrowing->shape)->toBe(Shape::Elsewhere)
        ->and($records[0]->reach())->toBe('elsewhere');
});

test('a wildcard rule carrying a record key is reported by neither list', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow($role)->to('view', $post);

    permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('entity_id')
        ->update(['entity_type' => '*']);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->wider)->toBeEmpty()
        ->and($state->records)->toBeEmpty()
        ->and($state->stances)->toBeEmpty();
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

test('a denial and a grant on one ordinary cell are two rows, and the cell reads denied', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('view', Post::class);
    Warden::forbid($role)->to('view', Post::class);

    $state = RoleGrants::of($role, gridCatalog());

    expect(grantCount())->toBe(2)
        ->and($state->stances[Post::class]['view'])->toBe(Stance::Forbidden->value)
        ->and($state->wider)->toBeEmpty();
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

test('changing a condition stops the old one authorizing', function (): void {
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

test('two rows for one cell are shown, said out loud, and never chosen between', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('title', 'alpha');
    Warden::allow($role)->to('update', Post::class);

    $narrowing = RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['update'];

    // Not editable and never was; what changed in `2.2.0` is that it is now
    // CLEARABLE, which is a smaller permission than being writable — emptying
    // reads no reach and rebuilds none. Asked to move to another stance it
    // still writes nothing, and now says so instead of going quiet.
    expect($narrowing->shape)->toBe(Shape::Tangled)
        ->and($narrowing->isEditable())->toBeFalse()
        ->and($narrowing->isClearable())->toBeTrue();

    $before = grantCount();

    $report = RoleGrants::apply($role, gridCatalog(), [Post::class => ['update' => 'forbidden']]);

    expect($report->written)->toBe(0)
        ->and(grantCount())->toBe($before)
        ->and($report->unresolved)->toBe([['row' => Post::class, 'action' => 'update']]);
});

test('clearing a tangled cell takes both of its rules with it', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('update', Post::class)->where('title', 'alpha');
    Warden::allow($role)->to('update', Post::class);

    expect(RoleGrants::of($role, $catalog)->narrowings[Post::class]['update']->shape)
        ->toBe(Shape::Tangled);

    RoleGrants::apply($role, $catalog, []);

    expect(grantCount())->toBe(0)
        ->and(RoleGrants::of($role, $catalog)->stances)->toBeEmpty();
});

test('flipping a tangled cell does not widen it to every row', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('update', Post::class)->where('title', 'alpha');
    Warden::allow($role)->to('update', Post::class);

    // The stance moves and NOTHING says anything about the reach — which is the
    // shape a real click has, because the browser is never handed a tangled
    // cell's narrowing to send back. A save that read that silence as "every
    // row" would turn a rule that names one title into one that names none.
    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'forbidden']]);

    $narrowing = RoleGrants::of($role, $catalog)->narrowings[Post::class]['update'] ?? null;

    expect($narrowing?->shape)->not->toBe(Shape::All);
});

test('an application that vetoes the grant is answered, not argued with', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(GrantingPermission::class, static fn (): bool => false);

    $role = makeRole();

    saveUpdateOnPosts($role, [Post::class => ['update' => conditionOn('title', 'alpha')]]);

    expect(grantCount())->toBe(0);
});

test('a veto scoped to one name in the list kills every name grouped with it', function (): void {
    config()->set('warden.cancellable_events', true);

    Event::listen(GrantingPermission::class, static fn (GrantingPermission $event): bool => ! in_array('view', $event->permissions, true));

    $role = makeRole();
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [Post::class => [
        'viewAny' => 'granted',
        'view' => 'granted',
    ]]);

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

    // Warden's own wording, pinned so that a third change to its generator is
    // seen here rather than in somebody's catalogue. Before 2.0 this read
    // `ViewAny posts`, which is the wart that made the question worth asking.
    expect($permission->getAttribute('title'))->toBe('View any posts');
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
        ->and($state->narrowings[Post::class]['viewAny']->isEditable())->toBeFalse();
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
    // Never with an argument. `Tenancy::dontScopeRoleGrants(bool $dont = true)`
    // NEGATES what it is handed — `dontScopeRoleGrants(false)` restores the
    // scoping — and neither its docblock nor warden's README says so, so
    // forwarding the config value inverts the polarity in silence. Reported
    // upstream as ficha 051.
    Warden::tenant()->dontScopeRoleGrants();

    $role = makeRole();
    $catalog = gridCatalog();

    Warden::tenant()->onceTo(7, static function () use ($role, $catalog): void {
        Warden::allow($role)->to('viewAny', Post::class);

        // The row itself, and this is the only assertion the inversion moves.
        // The three below hold in BOTH polarities — under tenant 7 a scoped
        // role grant lands at 7 and `writeScope(forRoleGrant: true)` answers 7
        // as well, so the cell reads `Shape::All` and stays in the diff either
        // way. Measured: with `dontScopeRoleGrants(false)` in place of the bare
        // call, only this first expectation goes red.
        expect(heldGrantScope($role))->toBeNull();

        $state = RoleGrants::of($role, $catalog);

        expect($state->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::All)
            ->and(RoleGrants::changes($role, $catalog, []))->toHaveCount(1);
    });
});

test("a row scoped as an integer is still this screen's to write under a string tenant id", function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', Post::class);
    });

    Warden::tenant()->onceTo('7', function () use ($role, $catalog): void {
        expect(RoleGrants::of($role, $catalog)->narrowings[Post::class]['viewAny']->shape)
            ->toBe(Shape::All);
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
        ->and($state->narrowings[Post::class]['update']->isEditable())->toBeFalse()
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

test('a narrowed cell cycled back to granted is granted, not stuck forbidden', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    $alpha = Post::query()->create(['title' => 'alpha']);

    $narrowing = [Post::class => ['update' => conditionOn('title', 'alpha')]];

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['update' => 'granted']], $narrowing);
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['update' => 'forbidden']], $narrowing);
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['update' => 'granted']], $narrowing);

    expect(RoleGrants::of($role, gridCatalog())->stances[Post::class]['update'])->toBe('granted')
        ->and(Access::granted($user, 'update', $alpha))->toBeTrue()
        ->and(grantCount())->toBe(1);
});

test('a rule the browser really changed is written as the browser sent it', function (): void {
    $role = makeRole();
    Warden::allow($role)->to('viewAny', Post::class)->where('published', '=', true);

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], [
        Post::class => ['viewAny' => [
            'mode' => 'conditions',
            'rules' => [[
                'logic' => 'and', 'kind' => 'value', 'column' => 'published',
                'operator' => '=', 'value' => 'false', 'authority' => '',
            ]],
        ]],
    ]);

    /** @var array{g: array{i: list<array{0: string, 1: array<string, mixed>}>}} $after */
    $after = permissionClass()::query()->withoutGlobalScopes()
        ->whereNotNull('options')->orderByDesc('id')->firstOrFail()->getAttribute('options');

    expect($after['g']['i'][0][1]['v'])->toBeFalse();
});

test('a numeric string keeps being a string when only the stance moves', function (): void {
    $role = makeRole();
    Warden::allow($role)->to('viewAny', Post::class)->where('id', '=', '2');

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'forbidden']], [
        Post::class => ['viewAny' => [
            'mode' => 'conditions',
            'rules' => [[
                'logic' => 'and', 'kind' => 'value', 'column' => 'id',
                'operator' => '=', 'value' => '2', 'authority' => '',
            ]],
        ]],
    ]);

    /** @var array{g: array{i: list<array{0: string, 1: array<string, mixed>}>}} $after */
    $after = permissionClass()::query()->withoutGlobalScopes()
        ->whereNotNull('options')->orderByDesc('id')->firstOrFail()->getAttribute('options');

    expect($after['g']['i'][0][1]['v'])->toBe('2')
        ->and(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('forbidden');
});

test('a decimal string keeps being a string when only the stance moves', function (): void {
    $role = makeRole();
    Warden::allow($role)->to('viewAny', Post::class)->where('id', '=', '2.5');

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'forbidden']], [
        Post::class => ['viewAny' => [
            'mode' => 'conditions',
            'rules' => [[
                'logic' => 'and', 'kind' => 'value', 'column' => 'id',
                'operator' => '=', 'value' => '2.5', 'authority' => '',
            ]],
        ]],
    ]);

    /** @var array{g: array{i: list<array{0: string, 1: array<string, mixed>}>}} $after */
    $after = permissionClass()::query()->withoutGlobalScopes()
        ->whereNotNull('options')->orderByDesc('id')->firstOrFail()->getAttribute('options');

    expect($after['g']['i'][0][1]['v'])->toBe('2.5')
        ->and(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('forbidden');
});

test('a fresh owned grant leaves one row, not more', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::ownedVia(Post::class, 'title');

    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'granted']], [
        Post::class => ['update' => ['mode' => 'owned', 'rules' => []]],
    ]);

    expect(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, $catalog)->narrowings[Post::class]['update']->shape)->toBe(Shape::Owned);
});

test('a granted owned cell turned to forbidden leaves one row, not two', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::ownedVia(Post::class, 'title');
    $narrowings = [Post::class => ['update' => ['mode' => 'owned', 'rules' => []]]];

    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'granted']], $narrowings);
    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'forbidden']], $narrowings);

    expect(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, $catalog)->stances[Post::class]['update'])->toBe('forbidden');
});

test('a forbidden owned cell turned back to granted leaves one row too', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::ownedVia(Post::class, 'title');
    $narrowings = [Post::class => ['update' => ['mode' => 'owned', 'rules' => []]]];

    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'forbidden']], $narrowings);
    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'granted']], $narrowings);

    expect(grantCount())->toBe(1)
        ->and(RoleGrants::of($role, $catalog)->stances[Post::class]['update'])->toBe('granted');
});

test('an owned cell returned to abstaining leaves nothing behind', function (string $from): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::ownedVia(Post::class, 'title');

    RoleGrants::apply($role, $catalog, [Post::class => ['update' => $from]], [
        Post::class => ['update' => ['mode' => 'owned', 'rules' => []]],
    ]);
    RoleGrants::apply($role, $catalog, []);

    expect(grantCount())->toBe(0);
})->with(['granted', 'forbidden']);

test('three cells on the same entity land in the group their own stance owns, not each others', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [Post::class => [
        'viewAny' => 'granted',
        'view' => 'granted',
        'update' => 'forbidden',
    ]]);

    $stances = RoleGrants::of($role, $catalog)->stances[Post::class];

    expect(grantCount())->toBe(3)
        ->and($stances['viewAny'])->toBe('granted')
        ->and($stances['view'])->toBe('granted')
        ->and($stances['update'])->toBe('forbidden');
});

test('writing five cells sharing an entity and stance is capped at 28, twenty-five measured grouped', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    DB::flushQueryLog();
    DB::enableQueryLog();

    RoleGrants::apply($role, $catalog, [Post::class => [
        'viewAny' => 'granted',
        'view' => 'granted',
        'create' => 'granted',
        'update' => 'granted',
        'delete' => 'granted',
    ]]);

    $reads = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(28)
        ->and(grantCount())->toBe(5);
});

test('writing one cell alone still costs what grouping cannot shrink, capped at 12, nine measured', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    DB::flushQueryLog();
    DB::enableQueryLog();

    RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'granted']]);

    $reads = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(12);
});

test('the transaction opens on warden own connection, not the default one', function (): void {
    config()->set('database.connections.warden_write', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('warden.connection', 'warden_write');
    Context::resolve()->setConnection('warden_write');

    $installPath = InstalledVersions::getInstallPath('elpandape/warden');

    /** @var Migration $migration */
    $migration = require $installPath.'/database/migrations/create_warden_tables.php.stub';
    $migration->up(); // @phpstan-ignore method.notFound

    $role = makeRole();
    $catalog = gridCatalog();

    Event::listen(PermissionGranted::class, static function (): void {
        throw new RuntimeException('interrupted mid-grant, on purpose');
    });

    expect(static function () use ($role, $catalog): void {
        RoleGrants::apply($role, $catalog, [Post::class => ['viewAny' => 'granted']]);
    })->toThrow(RuntimeException::class)
        ->and(grantCount())->toBe(0);
});

test('two different conditions saved together keep their own twin, not one shared', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    RoleGrants::apply($role, $catalog, [
        Post::class => ['viewAny' => 'granted', 'update' => 'granted'],
    ], [
        Post::class => [
            'viewAny' => conditionOn('title', 'alpha'),
            'update' => conditionOn('title', 'beta'),
        ],
    ]);

    $state = RoleGrants::of($role, $catalog);

    expect($state->narrowings[Post::class]['viewAny']->rules[0]->value)->toBe('alpha')
        ->and($state->narrowings[Post::class]['update']->rules[0]->value)->toBe('beta')
        ->and(grantCount())->toBe(2);
});

test('a cell nobody touched is left as somebody else set it', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    $wasShowing = ['stances' => [], 'narrowing' => []];

    Warden::allow($role)->to('viewAny', Post::class);

    $report = RoleGrants::apply($role, $catalog, [], null, $wasShowing);

    expect(Access::granted($role, 'viewAny', Post::class))->toBeTrue()
        ->and($report->preserved)->toBe(1)
        ->and($report->written)->toBe(0)
        ->and($report->refused)->toBeEmpty();
});

test('a cell this person moved is written when nobody else moved it', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    $wasShowing = ['stances' => [], 'narrowing' => []];

    $report = RoleGrants::apply(
        $role,
        $catalog,
        [Post::class => ['viewAny' => 'granted']],
        null,
        $wasShowing,
    );

    expect(Access::granted($role, 'viewAny', Post::class))->toBeTrue()
        ->and($report->written)->toBe(1)
        ->and($report->preserved)->toBe(0)
        ->and($report->refused)->toBeEmpty();
});

test('a cell two people moved apart is refused, not overwritten', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    $wasShowing = ['stances' => [], 'narrowing' => []];

    Warden::forbid($role)->to('viewAny', Post::class);

    $report = RoleGrants::apply(
        $role,
        $catalog,
        [Post::class => ['viewAny' => 'granted']],
        null,
        $wasShowing,
    );

    expect(Access::granted($role, 'viewAny', Post::class))->toBeFalse()
        ->and($report->written)->toBe(0)
        ->and($report->preserved)->toBe(0)
        ->and($report->refused)->toBe([['row' => Post::class, 'action' => 'viewAny']]);
});

test('two people who set the same cell the same way do not annoy each other', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    $wasShowing = ['stances' => [], 'narrowing' => []];

    Warden::allow($role)->to('viewAny', Post::class);

    $before = grantCount();

    $report = RoleGrants::apply(
        $role,
        $catalog,
        [Post::class => ['viewAny' => 'granted']],
        null,
        $wasShowing,
    );

    expect(grantCount())->toBe($before)
        ->and($report->metAnother())->toBeFalse()
        ->and($report->written)->toBe(0)
        ->and($report->refused)->toBeEmpty();
});

test('without a baseline every cell counts as touched', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('viewAny', Post::class);

    $report = RoleGrants::apply($role, $catalog, []);

    expect(Access::granted($role, 'viewAny', Post::class))->toBeFalse()
        ->and($report->written)->toBe(1)
        ->and($report->metAnother())->toBeFalse();
});

test('a lone editor can still clear a narrowed cell when the builder is switched off', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->toOwn(Post::class, 'viewAny');

    $wasShowing = ['stances' => [Post::class => ['viewAny' => 'granted']], 'narrowing' => []];

    $report = RoleGrants::apply($role, $catalog, [], null, $wasShowing);

    expect(grantCount())->toBe(0)
        ->and($report->written)->toBe(1)
        ->and($report->refused)->toBeEmpty();
});

test('a lone editor can still clear a cell whose reach this screen cannot rebuild', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();

    Warden::allow($role)->to('viewAny', Post::class)->where('a_column_that_left', '=', 'x');

    $wasShowing = [
        'stances' => [Post::class => ['viewAny' => 'granted']],
        'narrowing' => [Post::class => ['viewAny' => ['mode' => 'conditions', 'rules' => [
            ['logic' => 'and', 'column' => 'a_column_that_left', 'operator' => '=', 'value' => 'x'],
        ]]]],
    ];

    $report = RoleGrants::apply($role, $catalog, [], [], $wasShowing);

    expect($report->refused)->toBeEmpty()
        ->and($report->written)->toBe(1);
});

test('a payload with an entity missing revokes it, which is why a filter may never prune', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser();
    Warden::assign($role)->to($holder);

    $catalog = gridCatalog();
    $full = ['stances' => RoleGrants::of($role, $catalog)->stances];

    $pruned = $full['stances'];
    unset($pruned[Post::class]);

    $report = RoleGrants::apply($role, $catalog, $pruned, null, $full);

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue()
        ->and($report->written)->toBe(1)
        ->and($report->revoked)->toBe(1)
        ->and($report->refused)->toBeEmpty()
        ->and($report->preserved)->toBe(0);
});

test('a narrowing map with a cell missing widens it, for the same reason', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class)->where('title', '=', 'alpha');

    $catalog = gridCatalog();
    $stored = RoleGrants::of($role, $catalog);
    $baseline = $stored->toPayload();

    expect($stored->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::Conditions);

    $narrowings = $baseline['narrowing'];
    unset($narrowings[Post::class]['viewAny']);

    $report = RoleGrants::apply($role, $catalog, $baseline['stances'], $narrowings, $baseline);

    expect(RoleGrants::of($role, $catalog)->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::All)
        ->and($report->written)->toBe(1);

    // A map that is absent altogether is the safe half of the same question,
    // and it is the branch that makes the one above a hazard rather than a
    // defect: nobody touched any reach, so the store keeps what it holds.
    Warden::allow($role)->to('viewAny', Post::class)->where('title', '=', 'alpha');

    RoleGrants::apply($role, $catalog, $baseline['stances'], null, $baseline);

    expect(RoleGrants::of($role, $catalog)->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::Conditions);
});

test('a door carrying a hand-written condition is still written, not swallowed', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);
    $catalog = gridCatalog();
    $door = 'page:'.Reports::class;

    RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'granted']]);

    // A door has no entity, so warden's chain refuses a condition on it. The
    // stored row can carry one anyway — a seeder, a console, a hand edit — and
    // `Narrowing::of()` reads `options` without ever asking for `entity_type`,
    // so the cell comes back `Shape::Conditions` with nothing to test against.
    // Written out of band on purpose: through the model the `array` cast would
    // re-encode it, and what is being reproduced is a row that already exists.
    $class = Context::resolve()->permissionClass();
    $class::query()->withoutGlobalScopes()->where('name', $door)->update([
        'options' => '{"v":1,"g":{"t":"group","i":[["and",{"t":"value","c":"title","o":"=","v":"alpha"}]]}}',
    ]);

    // `identity_key` is computed on save and the raw update above skips that,
    // so it still describes a row with no conditions. Left stale, warden's own
    // lookup misses the row, tries to insert its plain twin and dies on the
    // unique index — which is the fixture failing, not the defect. Recomputed
    // here so the row is the one a seeder would really have left behind.
    $row = $class::query()->withoutGlobalScopes()->where('name', $door)->firstOrFail();
    $class::query()->withoutGlobalScopes()->whereKey($row->getKey())
        ->update(['identity_key' => PermissionIdentity::for($row)]);

    expect(RoleGrants::of($role, $catalog)->narrowings[$door][StateKey::DOOR]->shape)
        ->toBe(Shape::Conditions);

    // Moving that cell must still land its stance. It goes to `narrow()`,
    // whose `where()` throws "Constraints need an entity to test". What makes
    // that survivable is WHERE warden throws: `reconstrain()` walks
    // `lastGranted` and refuses ahead of its own transaction, so the plain
    // grant already asked for stands and only the condition is dropped. This
    // pins that ordering against the vendor — if warden ever moved the refusal
    // after the delete, the cell would lose its grant while the report still
    // counted it written.
    $report = RoleGrants::apply($role, $catalog, [$door => [StateKey::DOOR => 'forbidden']]);

    expect($report->written)->toBe(1)
        ->and(Access::granted($user, $door))->toBeFalse()
        ->and(RoleGrants::of($role, $catalog)->stances[$door][StateKey::DOOR])->toBe('forbidden');
});

test('an unreadable twin is still reachable when its cell is cleared', function (): void {
    $role = makeRole();
    $catalog = gridCatalog();
    $class = Context::resolve()->permissionClass();

    // A plain grant beside a narrowed one: two rows for one cell, which is the
    // only shape this screen will clear without being able to edit it.
    Warden::allow($role)->to('update', Post::class)->where('title', 'alpha');
    Warden::allow($role)->to('update', Post::class);

    $twin = $class::query()->withoutGlobalScopes()
        ->where('name', 'update')->whereNotNull('options')->orderByDesc('id')->firstOrFail();

    // Straight onto the column: through the model the `array` cast re-encodes it
    // into valid JSON, which is the step that hides this class of row.
    //
    // `identity_key` is left as warden wrote it, and that is the faithful shape:
    // a blob corrupted after the fact keeps the digest of the rule it used to
    // hold, which is what a real row in this state looks like.
    $class::query()->withoutGlobalScopes()->whereKey($twin->getKey())
        ->update(['options' => '{"v":1,"g":']);

    expect(RoleGrants::of($role, $catalog)->narrowings[Post::class]['update']->isClearable())
        ->toBeTrue()
        ->and(grantCount())->toBe(2);

    // The twin has to be reached BY ITS MODEL: since warden's 2.0 a name
    // resolves only the plain row. `twinsHeld()` collects the models to send,
    // and reading the cast rather than the column made it skip exactly this row
    // — so the clear reported success and left half the grant standing.
    RoleGrants::apply($role, $catalog, [Post::class => ['update' => 'none']]);

    expect(grantCount())->toBe(0);
});

test('an expired grant reads as an abstention, never as the tick it used to be', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('viewAny', Post::class);

    expect(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('granted');

    Carbon::setTestNow('2026-09-09 12:00:00');

    $state = RoleGrants::of($role, gridCatalog());

    // The row is still there and warden already stopped reading it. Drawing the
    // stance the row carries rather than the one the store answers would put a
    // tick on a cell that authorises nothing — the grid telling a person they
    // granted access the store denies.
    expect($state->stances[Post::class]['viewAny'] ?? 'absent')->toBe('abstain')
        ->and(grantCount())->toBe(1);

    Carbon::setTestNow();
});

test('a cell keeps the date it ended on, which is what tells a lapse from a blank', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('viewAny', Post::class);

    Carbon::setTestNow('2026-09-09 12:00:00');

    $payload = RoleGrants::of($role, gridCatalog())->toPayload();

    // Without the date the browser cannot tell a cell whose access ran out from
    // one nobody ever wrote: both say `none`, and only one of them is a story a
    // person needs.
    expect($payload['until'][Post::class]['viewAny'])->toStartWith('2026-09-08T12:00:00');

    Carbon::setTestNow();
});

test('an expired rule over every entity reaches nothing', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('viewAny', '*');

    expect(RoleGrants::of($role, gridCatalog())->wider)->toBe(['viewAny' => 'granted']);

    Carbon::setTestNow('2026-09-09 12:00:00');

    // `wider` is what paints the hollow tick on every cell of that name. A row
    // the clock retired reaching all of them would be the widest lie the grid
    // can tell in one pass.
    expect(RoleGrants::of($role, gridCatalog())->wider)->toBeEmpty();

    Carbon::setTestNow();
});

test('an expired row beside a live one is one row, not a tangle', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    // Two rows of the same polarity for one cell is what the grid draws as
    // unreadable — but only while both count. Letting the retired one join the
    // tally would lock a cell whose store has exactly one live rule in it, and
    // locking it is the one thing a person cannot undo from the screen.
    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('viewAny', Post::class)->where('title', '=', 'alpha');
    Warden::allow($role)->to('viewAny', Post::class);

    Carbon::setTestNow('2026-09-09 12:00:00');

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->stances[Post::class]['viewAny'])->toBe('granted')
        ->and($state->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::All);

    Carbon::setTestNow();
});

test('a live grant carries its date without moving its stance', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->stances[Post::class]['viewAny'])->toBe('granted')
        ->and($state->untils[Post::class]['viewAny']->toIso8601String())->toStartWith('2026-09-14T12:00:00');

    Carbon::setTestNow();
});

test('a grant with no date carries none, so the map only holds cells that end', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(RoleGrants::of($role, gridCatalog())->untils)->toBeEmpty()
        ->and(RoleGrants::of($role, gridCatalog())->toPayload()['until'])->toBeEmpty();
});

test('an expired grant over one record is not reported as one the role still holds', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('view', $post);

    expect(RoleGrants::of($role, gridCatalog())->records)->toHaveCount(1);

    Carbon::setTestNow('2026-09-09 12:00:00');

    // A record pin is printed above the grid as a rule the role holds that no
    // cell can show. Printing one the clock retired says the role reaches a row
    // it does not, in the one place the screen offers no way to check.
    expect(RoleGrants::of($role, gridCatalog())->records)->toBeEmpty();

    Carbon::setTestNow();
});

test('a granted cell is written with the date the screen sent', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], null, null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-14 12:00:00')],
    ]);

    expect(RoleGrants::of($role, gridCatalog())->untils[Post::class]['viewAny']->toIso8601String())
        ->toStartWith('2026-09-14T12:00:00');

    Carbon::setTestNow();
});

test('a forbidden cell is written with no date, whatever the browser sends', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    // The payload carries a date for a cell being forbidden — which no screen of
    // this package offers, and which is exactly why the guard is on the server
    // (§6.24, second layer). Warden throws on `ForbidsPermissions::until()`
    // UNCONDITIONALLY, `null` included, so getting this wrong is not a silently
    // wrong row: it is a 500 on save.
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'forbidden']], null, null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-14 12:00:00')],
    ]);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->stances[Post::class]['viewAny'])->toBe('forbidden')
        ->and($state->untils)->toBeEmpty();

    Carbon::setTestNow();
});

test('a date already past is not written, and the cell is named instead', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-09 12:00:00');

    // The screen hands back the date it was given, so a cell that lapsed and is
    // being switched on again arrives carrying the date it died on. Writing it
    // grants nothing and reports success; dropping it grants forever. Neither is
    // the click, so the cell is left alone and said out loud.
    $report = RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], null, null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-08 12:00:00')],
    ]);

    expect($report->lapsed)->toBe([['row' => Post::class, 'action' => 'viewAny']])
        ->and($report->written)->toBe(0)
        ->and(grantCount())->toBe(0);

    Carbon::setTestNow();
});

test('two cells ending on different days do not share one call', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted', 'view' => 'granted']], null, null, [
        Post::class => [
            'viewAny' => CarbonImmutable::parse('2026-09-14 12:00:00'),
            'view' => CarbonImmutable::parse('2026-09-21 12:00:00'),
        ],
    ]);

    // One `to()` call carries one date for every name in it, so grouping by
    // entity alone would stamp both cells with whichever date got there first.
    $untils = RoleGrants::of($role, gridCatalog())->untils[Post::class];

    expect($untils['viewAny']->toIso8601String())->toStartWith('2026-09-14T12:00:00')
        ->and($untils['view']->toIso8601String())->toStartWith('2026-09-21T12:00:00');

    Carbon::setTestNow();
});

test('a screen that does not offer dates keeps every date the store holds', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class);

    // Null, not an empty map. An empty map means "cleared", and reading the two
    // as one would end every timed grant on the grid the first time somebody
    // saved from a screen with the feature switched off.
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted', 'view' => 'granted']]);

    expect(RoleGrants::of($role, gridCatalog())->untils[Post::class]['viewAny']->toIso8601String())
        ->toStartWith('2026-09-14T12:00:00');

    Carbon::setTestNow();
});

test('a map without a cell key clears that cell date, which is how a date is removed', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class);

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], null, null, []);

    expect(RoleGrants::of($role, gridCatalog())->untils)->toBeEmpty()
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('granted');

    Carbon::setTestNow();
});

test('moving only the date is a write, not a cell that changed nothing', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class);

    $report = RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], null, null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-21 12:00:00')],
    ]);

    expect($report->written)->toBe(1)
        ->and(RoleGrants::of($role, gridCatalog())->untils[Post::class]['viewAny']->toIso8601String())
        ->toStartWith('2026-09-21T12:00:00')
        // One row, moved. The date is outside the unique index, so warden's
        // firstOrCreate finds the row it already has and changes it.
        ->and(grantCount())->toBe(1);

    Carbon::setTestNow();
});

test('narrowing a grant does not widen its life', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    // Warden's own guarantee, pinned here because this package depends on it and
    // would otherwise have to re-apply the date after every `where()`:
    // `reconstrain()` deletes the grant and re-creates it against the twin, and
    // carries `expires_at` across on the way.
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], [
        Post::class => ['viewAny' => conditionOn('title', 'alpha')],
    ], null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-14 12:00:00')],
    ]);

    $state = RoleGrants::of($role, gridCatalog());

    expect($state->narrowings[Post::class]['viewAny']->shape)->toBe(Shape::Conditions)
        ->and($state->untils[Post::class]['viewAny']->toIso8601String())->toStartWith('2026-09-14T12:00:00');

    Carbon::setTestNow();
});

test('moving only the date leaves the rule byte for byte as the store wrote it', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    // The value is stored as the STRING '2'. A payload carries every value as
    // text, so rebuilding the rule from one turns it into the integer 2 — a
    // different twin, matching different rows (§6.15). Whether the reach moved
    // and whether the date moved are therefore two questions: fold them into one
    // flag and a date-only edit rebuilds the rule, which is the defect 1.4.0
    // exists to keep shut, reached through a door it did not have then.
    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class)->where('id', '=', '2');

    $before = latestPermission('viewAny')->getAttribute('options');

    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'granted']], [
        Post::class => ['viewAny' => [
            'mode' => 'conditions',
            'rules' => [[
                'logic' => 'and', 'kind' => 'value', 'column' => 'id',
                'operator' => '=', 'value' => '2', 'authority' => '',
            ]],
        ]],
    ], null, [
        Post::class => ['viewAny' => CarbonImmutable::parse('2026-09-21 12:00:00')],
    ]);

    $state = RoleGrants::of($role, gridCatalog());

    expect(latestPermission('viewAny')->getAttribute('options'))->toBe($before)
        ->and($state->untils[Post::class]['viewAny']->toIso8601String())->toStartWith('2026-09-21T12:00:00')
        ->and(grantCount())->toBe(1);

    Carbon::setTestNow();
});

test('a rule that can never be true is not written, and does not leave a plain grant behind', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    $post = Post::query()->create(['title' => 'alpha']);

    // `title` is a text column with no bool cast, and `Value::cast('true')` makes
    // this a PHP boolean, so warden 3.0 refuses the condition. The refusal
    // arrives from `reconstrain()`, AFTER `narrow()` has already asked for the
    // plain grant — and `narrow()` catches `ConfigurationException` for two other
    // causes, so without a check ahead of warden the catch swallows it and what
    // survives is an unconditional grant. Measured on this branch: one row with
    // `options = null`, the cell redrawn as every row, and `Access::granted()`
    // answering true for a record the rule never named.
    $report = RoleGrants::apply($role, gridCatalog(), [Post::class => ['view' => 'granted']], [
        Post::class => ['view' => conditionOn('title', 'true')],
    ]);

    expect($report->impossible)->toBe([['row' => Post::class, 'action' => 'view']])
        ->and($report->written)->toBe(0)
        ->and(grantCount())->toBe(0)
        ->and(Access::granted($user, 'view', $post))->toBeFalse();
});

test('a stored rule that can never be true is drawn locked, not offered for editing', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    // The row a 2.x database carries: the string 'true' against a column the
    // model DOES cast to bool. Warden migrated none of them, and warden's own
    // fluent API can no longer produce one, so it goes in by hand — which is
    // exactly how it got there in the installations this protects.
    $row = latestPermission('viewAny');
    $row->forceFill(['options' => [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]])->save();

    $narrowing = RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['viewAny'];

    expect($narrowing->shape)->toBe(Shape::Unreadable)
        ->and($narrowing->reason)->toBe('unsatisfiable')
        ->and($narrowing->isEditable())->toBeFalse();

    // And the lock holds where it matters: a stance flip leaves the row alone
    // instead of re-pointing the grant at a fresh plain one.
    RoleGrants::apply($role, gridCatalog(), [Post::class => ['viewAny' => 'forbidden']]);

    expect(RoleGrants::of($role, gridCatalog())->narrowings[Post::class]['viewAny']->shape)
        ->toBe(Shape::Unreadable);
});

test('with nesting off a role inherits nothing, whatever the store holds', function (): void {
    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::allow($inner)->to('viewAny', Post::class);
    Warden::assign($inner)->to($outer);

    // The edge has always been writable and has always granted nothing. Reading
    // the flag here would be a second place for that answer to live; warden's
    // closure decides it, and with nesting off it returns direct edges only.
    expect(RoleGrants::of($outer, gridCatalog())->inherited)->toBeEmpty()
        ->and(RoleGrants::of($outer, gridCatalog())->stances)->toBeEmpty();
});

test('an inherited cell is drawn with the role that lends it, not left empty', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::allow($inner)->to('viewAny', Post::class);
    Warden::assign($inner)->to($outer);

    $state = RoleGrants::of($outer, gridCatalog());

    // The wildcard mistake of §6.11 wearing another hat: a cell that ANSWERS
    // must not read as one nobody wrote. And the link is half the point — a
    // hollow tick with no name says the grid knows something it will not say.
    expect($state->inherited[Post::class]['viewAny'])->toBe(['role' => 'Inner', 'stance' => 'granted'])
        // Not in `stances`: the outer role wrote nothing, and a save must not be
        // able to revoke somebody else's rule from a screen that never showed it
        // as theirs.
        ->and($state->stances)->toBeEmpty();
});

test('inheritance reaches two hops, because warden walks the closure', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $middle = makeRole('middle');
    $inner = makeRole('inner');

    Warden::allow($inner)->to('viewAny', Post::class);
    Warden::assign($inner)->to($middle);
    Warden::assign($middle)->to($outer);

    expect(RoleGrants::of($outer, gridCatalog())->inherited[Post::class]['viewAny']['role'])->toBe('Inner');
});

test('a rule of its own is what is in force, so nothing inherited is reported under it', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::allow($inner)->to('viewAny', Post::class);
    Warden::assign($inner)->to($outer);
    Warden::forbid($outer)->to('viewAny', Post::class);

    $state = RoleGrants::of($outer, gridCatalog());

    // The role's own forbid wins, and warden agrees. Reporting the inherited
    // grant underneath it would put two answers on one cell, and only one of
    // them is true.
    expect($state->stances[Post::class]['viewAny'])->toBe('forbidden')
        ->and($state->inherited)->toBeEmpty();
});

test('an inherited forbid is what the cell says, over an inherited grant', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $yes = makeRole('yes');
    $no = makeRole('no');

    Warden::allow($yes)->to('viewAny', Post::class);
    Warden::forbid($no)->to('viewAny', Post::class);
    Warden::assign($yes)->to($outer);
    Warden::assign($no)->to($outer);

    // Two inner roles disagreeing is warden's question and it answers forbidden.
    // The screen names the role that DECIDES, not the first one it read.
    expect(RoleGrants::of($outer, gridCatalog())->inherited[Post::class]['viewAny'])
        ->toBe(['role' => 'No', 'stance' => 'forbidden']);
});

test('a cycle stops at the depth warden caps it with, instead of hanging', function (): void {
    config()->set('warden.roles.nested', true);

    $a = makeRole('a');
    $b = makeRole('b');

    Warden::allow($b)->to('viewAny', Post::class);
    Warden::assign($b)->to($a);
    Warden::assign($a)->to($b);

    // Warden stops expanding at `warden.roles.max_depth` rather than throwing,
    // so this is a screen that renders rather than one that dies. The cell still
    // answers, from the role that lends it.
    expect(RoleGrants::of($a, gridCatalog())->inherited[Post::class]['viewAny']['role'])->toBe('B');
});

test('an inherited cell is not written by a save that never touched it', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::allow($inner)->to('viewAny', Post::class);
    Warden::assign($inner)->to($outer);

    // The grid sends back what it was drawn with. An inherited cell is not in
    // `stances`, so the save sees an abstention against an abstention and has
    // nothing to do — which is the whole reason it is kept out of that map.
    $report = RoleGrants::apply($outer, gridCatalog(), []);

    expect($report->written)->toBe(0)
        ->and(grantCount())->toBe(1)
        ->and(RoleGrants::of($outer, gridCatalog())->inherited[Post::class]['viewAny']['role'])->toBe('Inner');
});

test('a role with nesting on and nothing nested still costs no extra lookup', function (): void {
    config()->set('warden.roles.nested', true);

    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    // The early return that skips naming roles nobody assigned: with the flag on
    // and an empty closure there is nothing to look up, and asking anyway would
    // put a query on every grid render of every installation that turned nesting
    // on and never used it.
    expect(RoleGrants::of($role, gridCatalog())->inherited)->toBeEmpty()
        ->and(RoleGrants::of($role, gridCatalog())->stances[Post::class]['viewAny'])->toBe('granted');
});
