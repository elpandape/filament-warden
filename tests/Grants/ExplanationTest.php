<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Grants\Cause;
use ElPandaPe\FilamentWarden\Grants\Explanation;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

pest()->extend(TestCase::class);

function postCatalog(): Catalog
{
    return Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));
}

/**
 * How many statements one call makes, with the log emptied first.
 *
 * Exact numbers rather than a ceiling, unlike the caps elsewhere in this suite:
 * this one exists to CATCH a change, not to bound one. `explain()` is the thing
 * a person clicks a cell to get, and the rule that keeps it affordable is that
 * nothing calls it per row — a cost that moved without anybody noticing is how
 * that rule stops being true.
 */
function explainCost(callable $probe): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $probe();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

function entryFor(string $action): Entry
{
    foreach (postCatalog()->entries as $entry) {
        if ($entry->model === Post::class && $entry->name === $action) {
            return $entry;
        }
    }

    throw new RuntimeException("No entry [{$action}].");
}

/**
 * @param  array<string, array<string, bool>>  $narrowed
 */
function why(Model $role, string $action, array $narrowed = [], ?Stance $onScreen = null, ?Stance $stored = null): Explanation
{
    return Explanation::of($role, entryFor($action), Post::class, $action, $narrowed, $onScreen, $stored);
}

test('a permission the role holds itself says so, and names it', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny');

    expect($explanation->verdict)->toBe(Stance::Granted)
        ->and($explanation->cause)->toBe(Cause::GrantedDirectly)
        ->and($explanation->permission)->not->toBeNull()
        ->and($explanation->summary)->toContain($explanation->permission)
        ->and($explanation->role)->toBeNull();
});

test('an explicit denial says explicitly forbidden, not merely not allowed', function (): void {
    $role = makeRole();

    Warden::forbid($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny');

    expect($explanation->verdict)->toBe(Stance::Forbidden)
        ->and($explanation->cause)->toBe(Cause::ForbiddenDirectly)
        ->and($explanation->summary)->toContain('Explicitly forbidden');
});

test('abstaining and forbidding are two different answers', function (): void {
    $role = makeRole();

    $abstained = why($role, 'viewAny');

    Warden::forbid($role)->to('viewAny', Post::class);

    $forbidden = why($role, 'viewAny');

    expect($abstained->verdict)->toBe(Stance::Abstain)
        ->and($abstained->cause)->toBe(Cause::NoMatchingGrant)
        ->and($abstained->permission)->toBeNull()
        ->and($abstained->summary)->not->toBe($forbidden->summary);
});

test('a permission given to everyone says so', function (): void {
    $role = makeRole();

    Warden::allowEveryone()->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->cause)->toBe(Cause::GrantedToEveryone);
});

test('a denial applied to everyone says so too', function (): void {
    $role = makeRole();

    Warden::forbidEveryone()->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->cause)->toBe(Cause::ForbiddenToEveryone);
});

test('a role that holds another role is explained through it, and it is named', function (): void {
    $role = makeRole('editor');
    $inherited = makeRole('reader');

    Warden::allow($inherited)->to('viewAny', Post::class);
    Warden::assign($inherited)->to($role);

    $explanation = why($role, 'viewAny');

    expect($explanation->cause)->toBe(Cause::GrantedViaRole)
        ->and($explanation->role)->not->toBeNull()
        ->and($explanation->summary)->toContain($explanation->role);
});

test('a narrowed cell says more than the cause, which is true and not the reason', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $stored = RoleGrants::of($role, postCatalog());
    $explanation = why($role, 'update', $stored->narrowed());

    expect($explanation->cause)->toBe(Cause::ConditionsNotMet)
        ->and($explanation->narrowed)->not->toBeNull()
        ->and($explanation->narrowed)->toContain('with a record in front of it');
});

test('a cell with no narrowed rule says nothing about conditions', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->narrowed)->toBeNull();
});

test('a stance changed on screen and not saved is called out', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny', [], Stance::Abstain, Stance::Granted);

    expect($explanation->pending)->not->toBeNull()
        ->and($explanation->pending)->toContain('abstains');
});

test('a stance that matches the store is not called out', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny', [], Stance::Granted, Stance::Granted)->pending)->toBeNull();
});

test('the payload the browser paints carries every line already written', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(array_keys(why($role, 'viewAny')->toPayload()))
        ->toBe(['verdict', 'cause', 'summary', 'permission', 'role', 'narrowed', 'pending', 'until']);
});

test('every cause the package mirrors is one warden can produce', function (): void {
    foreach (ElPandaPe\Warden\Checks\Explain\Cause::cases() as $case) {
        expect(Cause::of($case)->value)->toBe($case->value);
    }

    expect(Cause::cases())->toHaveSameSize(ElPandaPe\Warden\Checks\Explain\Cause::cases());
});

test('a permission with no title is named by its name', function (): void {
    config()->set('warden.titles.autogenerate', false);

    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->permission)->toBe('viewAny');
});

test('a role that does not exist yet is explained, not answered with nothing', function (): void {
    $explanation = Explanation::unsaved();

    expect($explanation->verdict)->toBe(Stance::Abstain)
        ->and($explanation->cause)->toBeNull()
        ->and($explanation->summary)->toContain('has not been saved')
        ->and($explanation->permission)->toBeNull()
        ->and($explanation->role)->toBeNull()
        ->and($explanation->narrowed)->toBeNull()
        ->and($explanation->pending)->toBeNull()
        ->and(array_keys($explanation->toPayload()))
        ->toBe(['verdict', 'cause', 'summary', 'permission', 'role', 'narrowed', 'pending', 'until'])
        ->and($explanation->toPayload()['cause'])->toBeNull();
});

test("a grant with a future date says when it ends, beside warden's own cause", function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', Post::class);

    $why = Explanation::of(
        role: $role,
        entry: entryFor('viewAny'),
        rowKey: Post::class,
        action: 'viewAny',
        until: CarbonImmutable::parse('2026-09-14 12:00:00'),
    );

    // Beside the cause, never instead of it: warden still says granted-directly,
    // and that is true. The date is the part warden has no case for.
    expect($why->verdict)->toBe(Stance::Granted)
        ->and($why->cause)->toBe(Cause::GrantedDirectly)
        ->and($why->until)->toContain('Sep 14')
        ->and($why->summary)->not->toContain('Sep 14');

    Carbon::setTestNow();
});

test('a lapsed grant says so, because warden cannot: there is no Expired cause', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to('viewAny', Post::class);

    Carbon::setTestNow('2026-09-09 12:00:00');

    $why = Explanation::of(
        role: $role,
        entry: entryFor('viewAny'),
        rowKey: Post::class,
        action: 'viewAny',
        until: CarbonImmutable::parse('2026-09-08 12:00:00'),
    );

    // `Cause::Expired` does not exist: warden filters expiry in SQL, so a lapsed
    // grant comes back `NoMatchingGrant` — byte for byte what a cell nobody ever
    // wrote answers. Without this sentence the two are indistinguishable on
    // screen, and one of them is a story somebody needs.
    expect($why->verdict)->toBe(Stance::Abstain)
        ->and($why->cause)->toBe(Cause::NoMatchingGrant)
        ->and($why->until)->toContain('Sep 8')
        ->and($why->until)->toContain('warden:clean --expired');

    Carbon::setTestNow();
});

test('a cell with no date says nothing about one', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->until)->toBeNull();
});

test('explain costs between three and five queries, and the abstention that costs one', function (): void {
    $role = makeRole();
    $user = makeUser();

    // Measured against warden v3.0.0 on 2026-09-07, against a real query log and
    // not counted off the call sites. Unchanged from 2.2.1 in range — but the
    // NotApplicable branch is no longer FREE: warden reads `assigned_roles`
    // before it decides the entity is not a model class, so §6.13's "costs
    // zero" is now false. It is one query, and the query itself carries the
    // expiry filter, which is where the SQL side of the exclusive boundary
    // shows up.
    expect(explainCost(fn () => Warden::explain($role, 'viewAny', Post::class)))->toBe(3);

    Warden::allow($role)->to('viewAny', Post::class);

    expect(explainCost(fn () => Warden::explain($role, 'viewAny', Post::class)))->toBe(4);

    $inner = makeRole('inner');
    Warden::allow($inner)->to('view', Post::class);
    Warden::assign($inner)->to($user);

    expect(explainCost(fn () => Warden::explain($user, 'view', Post::class)))->toBe(5)
        ->and(explainCost(fn () => Warden::explain($role, 'viewAny', 'not-a-class')))->toBe(1);
});
