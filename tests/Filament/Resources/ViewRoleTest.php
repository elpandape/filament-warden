<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
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

    expect(array_keys($onThePage))->toBe(['stances', 'narrowing', 'until'])
        ->and(array_keys($state))->toBe(['stances', 'narrowing', 'until', 'baseline'])
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

test('the screen names an account that holds it', function (): void {
    $user = signIn();
    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('Amaru Quispe');
});

test('the screen names a holder restricted to a context too', function (): void {
    $user = signIn();
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);
    Warden::assign($role)->on($post)->to(makeUser('Amaru Quispe'));

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('Amaru Quispe');
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
        livewire(ViewRole::class, ['record' => $role->getKey()])
            ->assertSee('Nobody holds this role here')
            ->assertDontSee('Amaru Quispe');
    });
});
