<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Exceptions\ConfigurationException;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Models\Grant;
use ElPandaPe\Warden\Models\Permission;
use Illuminate\Support\Carbon;
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

test('both pivots carry the end date, from the creation stub alone', function (): void {
    // `TestCase` never calls `upgradeToV3()`, so this is what says the ground is
    // there: warden's creation stub already ships the 3.0 shape, and if it ever
    // stops, every expiry test downstream would fail on a missing column with no
    // one line saying why.
    expect(Schema::hasColumn('grants', 'expires_at'))->toBeTrue()
        ->and(Schema::hasColumn('assigned_roles', 'expires_at'))->toBeTrue();
});

test('a grant stops authorizing once its date has passed, with no command run', function (): void {
    $user = makeUser();

    // The clock is frozen for the whole case: a date written relative to a
    // moving `now()` makes a test that is green today and red at midnight.
    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($user)->until(Carbon::parse('2026-09-08 12:00:00'))->to('view', User::class);

    expect($user->can('view', User::class))->toBeTrue();

    Carbon::setTestNow('2026-09-09 12:00:00');

    expect($user->can('view', User::class))->toBeFalse()
        // The row is still there. Nothing swept it, and nothing had to.
        ->and(Grant::query()->count())->toBe(1);

    Carbon::setTestNow();
});

test('the boundary is exclusive: the row is gone at the instant it names', function (): void {
    $user = makeUser();
    $ends = Carbon::parse('2026-09-08 12:00:00');

    Carbon::setTestNow($ends->copy()->subSecond());

    Warden::allow($user)->until($ends)->to('view', User::class);

    expect($user->can('view', User::class))->toBeTrue();

    // Not one second past it — the instant itself. An inclusive boundary would
    // still answer true here, and the difference is invisible any other second.
    Carbon::setTestNow($ends);

    expect($user->can('view', User::class))->toBeFalse();

    Carbon::setTestNow();
});

test('a forbid refuses an end date instead of accepting one it would not honour', function (): void {
    $user = makeUser();

    // A prohibition that lapsed by clock would turn "a forbid beats every grant"
    // into "until Tuesday". No screen may offer this, and the store refuses it
    // even if one did.
    expect(fn () => Warden::forbid($user)->until(Carbon::tomorrow())->to('view', User::class))
        ->toThrow(ConfigurationException::class, 'A forbid does not expire');
});

test('until() before to(), because a grant executes on to() and cannot be amended after', function (): void {
    $user = makeUser();

    // The order is the trap, and it is why no helper in this suite wraps the
    // chain: hiding it would mean nothing ever exercises it.
    expect(fn () => Warden::allow($user)->to('view', User::class)->until(Carbon::tomorrow()))
        ->toThrow(ConfigurationException::class, 'Call until() before to()');
});

test('a grant reached through a role outlives neither: the earlier date ends it', function (): void {
    $user = makeUser();
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    // The grant runs a week; the assignment that reaches it runs a day.
    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('view', User::class);
    Warden::assign($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to($user);

    expect($user->can('view', User::class))->toBeTrue();

    // Past the assignment and well inside the grant. Every account-side screen
    // shows the earlier of the two because of this, never the grant's own date.
    Carbon::setTestNow('2026-09-09 12:00:00');

    expect($user->can('view', User::class))->toBeFalse();

    Carbon::setTestNow();
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
