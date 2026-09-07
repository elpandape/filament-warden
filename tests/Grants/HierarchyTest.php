<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Support\Carbon;

pest()->extend(TestCase::class);

test('a role tells the roles it was given from the ones those brought along', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $middle = makeRole('middle');
    $inner = makeRole('inner');

    Warden::assign($middle)->to($outer);
    Warden::assign($inner)->to($middle);

    $hierarchy = Hierarchy::of($outer);

    // The closure cannot tell the two apart on its own — every reachable role
    // comes back under one key space, with no marker for how far away it is —
    // and the screens draw exactly that distinction: "inherits from" is what
    // somebody chose, the rest is what the choice brought along.
    expect($hierarchy->direct)->toBe([$middle->getKey()])
        ->and($hierarchy->inherited)->toBe([$inner->getKey()])
        ->and($hierarchy->all())->toBe([$middle->getKey(), $inner->getKey()]);
});

test('who inherits a role does not include the role itself', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::assign($inner)->to($outer);

    // Warden's own `reaching()` includes the targets, because the readers that
    // ask it are filtering authorities and want the whole set. A screen saying
    // "inherited by" wants the opposite.
    expect(Hierarchy::reaching($inner))->toBe([$outer->getKey()])
        ->and(Hierarchy::reaching($outer))->toBeEmpty();
});

test('a lapsed edge is not an inheritance', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::assign($inner)->until(Carbon::parse('2026-09-08 12:00:00'))->to($outer);

    expect(Hierarchy::of($outer)->direct)->toBe([$inner->getKey()]);

    Carbon::setTestNow('2026-09-09 12:00:00');

    // `Role::nestedRoles()` would still list it: it is a plain `belongsToMany`
    // with a pivot condition and no expiry filter, so it names an edge the
    // engine has already stopped reading. That is why neither half of this
    // class goes through it.
    expect(Hierarchy::of($outer)->direct)->toBeEmpty();

    Carbon::setTestNow();
});

test('a role that has not been saved is inherited by nobody, and asks nothing', function (): void {
    config()->set('warden.roles.nested', true);

    // The create screen has one of these. A key that does not read as a key
    // could match no row anyway, so answering empty is the safe way to lose it —
    // and it saves the query that asking with a null key would make.
    expect(Hierarchy::reaching(new (roleClass())))->toBeEmpty();
});
