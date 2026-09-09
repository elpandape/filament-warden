<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Grants\RoleHolders;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

/**
 * Every test below signs in against two gates, not one: the resource opens on
 * `viewAny` and the record opens on `view`. Granting only the second answers with
 * a whole 403 page rather than a failed assertion, which reads like a broken test
 * instead of a missing grant.
 *
 * The "who holds it" section added to this screen's infolist reads
 * `assigned_roles` under the tenant this request is in, on purpose and unlike
 * `RolesTable::warning()`'s own wide read behind the delete button beside it
 * (§6.24) — 'the section stays under the tenant you are in, unlike the delete
 * warning beside it'.
 *
 * Neither the section nor the count column filters on `restricted_to_type`:
 * an assignment narrowed to a context is written to the same table with the
 * same `role_id`, no different from an unrestricted one, so it is counted and
 * named exactly the same — 'the screen names a holder restricted to a context
 * too'. The translated description of this section said the opposite for one
 * release; the sentence was wrong, not the code, and got corrected to match
 * what is actually true.
 *
 * The two screens no longer hand over byte-identical arrays and that is the
 * point of the second test here, not a loosened first one: only the form can be
 * saved, so only the form is stamped with what it was showing when it opened.
 * The shared halves still have to match, because they are still worked out in
 * one place — and the extra key has to be a copy of that same payload rather
 * than a second derivation of the store, which is what would drift.
 */
pest()->extend(TestCase::class);

/**
 * The literal object the template hands alpine, decoded.
 *
 * `Js::from()` writes a json string inside a json string, so it comes back the
 * way it went in: once for the javascript literal, once for the payload. The
 * pattern is anchored on `state:` because `grid:` is a second `JSON.parse` on
 * the next line of the same attribute.
 *
 * @return array<string, mixed>
 */
function stateHandedTo(string $html): array
{
    $matches = [];

    preg_match("/state: JSON\.parse\('(.+?)'\)/", $html, $matches);

    $literal = json_decode('"'.($matches[1] ?? '').'"');

    /** @var array<string, mixed> $state */
    $state = json_decode(
        is_string($literal) && $literal !== '' ? $literal : '{}',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $state;
}

test('an authority the store never trusted with this role does not read it', function (): void {
    signIn();

    $role = makeRole();

    livewire(ViewRole::class, ['record' => $role->getKey()])->assertForbidden();
});

test('the listing opens a resource, and the record still has its own gate', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])->assertForbidden();
});

test('reading a role is its own permission, apart from changing it', function (): void {
    $user = signIn();
    $role = makeRole('editor');

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('editor')
        ->assertOk();
});

test('the screen draws what the role holds', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertSee('data-state="granted"', escape: false);
});

test('the screen that only reads hands the browser what it drew', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());
    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    $state = stateHandedTo(livewire(ViewRole::class, ['record' => $role->getKey()])->html());

    expect(partOf($state, 'stances'))->toEqual([roleClass() => ['viewAny' => 'granted', 'update' => 'granted']])
        ->and(partOf($state, 'narrowing'))->toHaveKey(roleClass().'.update.mode', 'conditions');
});

test('both screens are handed the same payload, because it is worked out once', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($role)->to('viewAny', roleClass());
    Warden::allow($role)->to('update', roleClass())->where('name', '=', '2');

    $onThePage = stateHandedTo(livewire(ViewRole::class, ['record' => $role->getKey()])->html());

    $state = livewire(EditRole::class, ['record' => $role->getKey()])->get('data.permissions');
    $state = is_array($state) ? $state : [];

    expect(partOf($state, 'stances'))->toBe(partOf($onThePage, 'stances'))
        ->and(partOf($state, 'narrowing'))->toBe(partOf($onThePage, 'narrowing'));
});

test('only the form carries a baseline, and it is a copy of what it was handed', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($role)->to('viewAny', roleClass());
    Warden::allow($role)->to('update', roleClass())->where('name', '=', '2');

    $onThePage = stateHandedTo(livewire(ViewRole::class, ['record' => $role->getKey()])->html());

    $state = livewire(EditRole::class, ['record' => $role->getKey()])->get('data.permissions');
    $state = is_array($state) ? $state : [];

    expect(array_keys($onThePage))->toBe(['stances', 'narrowing', 'until', 'inherited'])
        ->and(array_keys($state))->toBe(['stances', 'narrowing', 'until', 'inherited', 'baseline'])
        ->and(partOf($state, 'baseline'))->toBe($onThePage);
});

test('no cell of a screen that only reads is a control that cycles', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-locked', escape: false)
        ->assertDontSee('x-on:click="pick(', escape: false)
        ->assertSee('x-on:click="select(', escape: false);
});

test('only a role the installation protects is announced as protected', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('create', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $plain = makeRole('editor');
    $protected = makeRole('super-admin');

    livewire(ViewRole::class, ['record' => $plain->getKey()])
        ->assertSee('x-on:click="select(', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false);

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false);

    livewire(CreateRole::class)
        ->assertDontSee('fw-locked-notice', escape: false);
});

test('a screen that only reads says so, and a protected role still says the other thing', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $plain = makeRole('editor');
    $protected = makeRole('super-admin');

    livewire(ViewRole::class, ['record' => $plain->getKey()])
        ->assertSee('fw-read-only-notice', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false)
        ->assertDontSee('fw-read-only-notice', escape: false);

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false)
        ->assertDontSee('fw-read-only-notice', escape: false);
});

test('the inspector answers here too, because understanding is reading', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::forbid($role)->to('delete', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'explainCell', [roleClass(), 'delete'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'forbidden');
});

test('a screen that cannot change anything never says something is unsaved', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['pending'] === null);
});

test('a screen that only reads still says how far a rule reaches', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'stored')['preview'] === 'name = editor');
});

test('the builder is on the read-only screen with nothing to operate', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-builder', escape: false)
        ->assertSee('interactive: false', escape: false);
});

test('the screen says who holds it, and says nothing when nobody does', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('Who holds it')
        ->assertSee('Nobody holds this role here');
});

test('the screen counts an account that holds it, and never names one', function (): void {
    $user = signIn();
    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee(__('filament-warden::ui.resources.roles.holders.held', ['count' => 1]))
        // And the empty state is not drawn beside the tally it contradicts: the
        // two are alternatives, and without the `visible()` on each of them the
        // card would say "Nobody holds this role here" over a count of one.
        ->assertDontSee('Nobody holds this role here')
        // The count is the whole answer. A role can be held by every account in
        // the installation, so ten names out of a thousand decorate rather than
        // inform — the one screen that names holders is the delete modal, where
        // the names are what is about to be destroyed.
        //
        // Positive assertion first and on purpose: an `assertDontSee` alone
        // passes just as well on a page that drew no holders section at all
        // (§6.34).
        ->assertDontSee('Amaru Quispe');
});

test('a holder restricted to a context is counted as restricted, not as here', function (): void {
    $user = signIn();
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);
    Warden::assign($role)->on($post)->to(makeUser('Amaru Quispe'));

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    // It counts in the total, and the breakdown says which of the three it is —
    // which is more than the old sentence could say, and it is the half that
    // matters: a restricted assignment is one this screen shows and deliberately
    // will not take back (§6.21).
    $tally = RoleHolders::of($role);

    expect($tally->total)->toBe(1)
        ->and($tally->restricted)->toBe(1)
        ->and($tally->here)->toBe(0);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee(__('filament-warden::ui.relations.roles.held.restricted'))
        ->assertDontSee('Amaru Quispe');
});

test('the section stays under the tenant you are in, unlike the delete warning beside it', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Amaru Quispe'));
    });

    Warden::tenant()->onceTo(8, function () use ($role): void {
        // Nobody, from over here — and it is the empty state in words rather
        // than four zeros, which is what the section draws when the tally is
        // empty. `assertDontSee` on a name would pass on any page since this
        // screen stopped naming holders at all, so the assertion that carries
        // the guarantee is the positive one.
        livewire(ViewRole::class, ['record' => $role->getKey()])
            ->assertSee('Nobody holds this role here')
            ->assertDontSee(__('filament-warden::ui.resources.roles.holders.held', ['count' => 1]));
    });
});

test('the heading carries the code name and the subheading the title', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    // `recordTitleAttribute` is `name`, because that is what a grant points at
    // and what a breadcrumb has to say. That leaves the title with nowhere to go
    // once the identity card is gone, and this is where Filament puts it.
    /** @var ViewRole $page */
    $page = livewire(ViewRole::class, ['record' => $role->getKey()])->instance();

    expect($page->getSubheading())->toBe($role->getAttribute('title'));

    // Two ways of having no title, and both answer null. `''` is not
    // hypothetical: §6.24 measured a disabled field arriving ABSENT rather than
    // null and a `?? null` reading it back as `''`, and Filament still draws the
    // subheading paragraph for an empty string — an empty line under the
    // heading, with nothing in it.
    foreach ([null, ''] as $stored) {
        $role->setAttribute('title', $stored);
        $role->save();

        /** @var ViewRole $untitled */
        $untitled = livewire(ViewRole::class, ['record' => $role->getKey()])->instance();

        expect($untitled->getSubheading())->toBeNull();
    }
});

test('an assignment written at another scope is counted apart from the ones here', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Amaru Quispe'));
    });

    Warden::assign($role)->to(makeUser('Nayra Mamani'));

    // Read from inside tenant 7: the global row is the one this tenant cannot
    // take back, because `retract()->from()` filters on `scope` exactly and
    // would delete nothing while reporting success (§6.21). The tally says which
    // is which, so the two figures are not the same number twice.
    Warden::tenant()->onceTo(7, static function () use ($role): void {
        $tally = RoleHolders::of($role->refresh());

        expect($tally->total)->toBe(2)
            ->and($tally->here)->toBe(1)
            ->and($tally->elsewhere)->toBe(1)
            ->and($tally->restricted)->toBe(0);
    });
});

test('an assignment that ends is still held, and counted twice on purpose', function (): void {
    $role = makeRole();

    Warden::assign($role)->until(now()->addWeek())->to(makeUser('Amaru Quispe'));

    // A separate axis and not a fourth kind of holder: the row is held today by
    // whoever holds it, and counted in whichever of the three names them. What
    // `ending` says is when that stops being true with nobody doing anything —
    // the same shape `Holders::ending` already has for a permission.
    $tally = RoleHolders::of($role);

    expect($tally->total)->toBe(1)
        ->and($tally->here)->toBe(1)
        ->and($tally->ending)->toBe(1);
});

test('the memo lets go when the store moves under it', function (): void {
    $role = makeRole();

    expect(RoleHolders::of($role)->total)->toBe(0);

    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    // Still the answer read before the write, which is the point of a memo and
    // the trap in one: the hand-out action writes and the page redraws in the
    // same request, so without an escape hatch the tally beside the button
    // would report what it said a moment ago.
    expect(RoleHolders::of($role)->total)->toBe(0);

    RoleHolders::forget($role);

    expect(RoleHolders::of($role)->total)->toBe(1);
});

test('a row that is both restricted and written elsewhere is counted as restricted', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);

    // Both at once, which is the ONLY fixture the priority of the two arms
    // decides. Measured: swapping them with a merely-restricted row leaves the
    // suite green, because a restriction written at this scope never reaches
    // the `elsewhere` arm at all — §6.30, the test goes on what the branch
    // DECIDES and not on how it is written.
    Warden::tenant()->onceTo(7, static function () use ($role, $post): void {
        Warden::assign($role)->on($post)->to(makeUser('Amaru Quispe'));
    });

    // Read with no tenant and the packaged `all`, so the row from tenant 7 is
    // visible: `readFilter()` adds no predicate, and `writeScope()` is null, so
    // the row answers true to both questions at once.
    $tally = RoleHolders::of($role);

    expect($tally->total)->toBe(1)
        // Restricted first, and it is the more useful of the two to say: it is
        // the half somebody can act on, and `Assignment::descriptions()` and
        // `RolesRelationManager::heldAs()` already order it this way about one
        // account's row. Three screens saying it in three orders is what §6.24
        // measures going wrong.
        ->and($tally->restricted)->toBe(1)
        ->and($tally->elsewhere)->toBe(0)
        ->and($tally->here)->toBe(0);
});
