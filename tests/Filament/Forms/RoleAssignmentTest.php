<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostElsewhere;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

/**
 * The two halves of this field's state come back in different types, and a test
 * that compares them strictly has to know it: the checkbox list round-trips its
 * values through the browser, so it returns role keys as STRINGS, while the
 * baseline is written server-side and keeps the integers the store gave. Nothing
 * in `Assignment` cares — `wants()` casts both sides — but `toContain` does.
 */
pest()->extend(TestCase::class);

function assignedCount(): int
{
    return Context::resolve()->assignedRoleClass()::query()->count();
}

test('the field keeps itself out of the data the account is updated with', function (): void {
    $field = RoleAssignment::make('roles');

    expect($field->isDehydrated())->toBeFalse()
        ->and($field->isSaved())->toBeTrue();
});

test('the field fills itself from what the account holds', function (): void {
    signIn();

    $account = makeUser('Holder');
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->assertSet('data.roles', [$role->getKey()]);
});

test('an account holding nothing opens on nothing', function (): void {
    signIn();

    makeRole('editor');

    livewire(AccountHost::class, ['accountKey' => makeUser('Holder')->getKey()])
        ->assertSet('data.roles', []);
});

test('handing a role out from the screen makes the store answer for it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('update', roleClass());

    $account = makeUser('Holder');
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse();

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->fillForm(['roles' => [$role->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($account, 'viewAny', Post::class))->toBeTrue();
});

test('taking it back stops the store answering', function (): void {
    $user = signIn();
    Warden::allow($user)->to('update', roleClass());

    $account = makeUser('Holder');
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::assign($role)->to($account);

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->fillForm(['roles' => []])
        ->call('save');

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse()
        ->and(assignedCount())->toBe(0);
});

test('a payload naming a role nobody may hand out writes nothing at all', function (): void {
    signIn();

    $account = makeUser('Holder');
    $role = makeRole('editor');

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->fillForm(['roles' => [$role->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(assignedCount())->toBe(0);
});

test('a payload omitting a role nobody may hand out does not take it away', function (): void {
    signIn();

    $account = makeUser('Holder');
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->fillForm(['roles' => []])
        ->call('save');

    expect(assignedCount())->toBe(1);
});

test('a role that cannot be handed out is drawn locked, with the reason beside it', function (): void {
    signIn();

    $account = makeUser('Holder');
    makeRole('editor');

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->assertSee('Editor')
        ->assertSee('cannot edit this role');
});

test('an assignment narrowed to a context is drawn locked too, and never written over', function (): void {
    $user = signIn();
    Warden::allow($user)->to('update', roleClass());

    $account = makeUser('Holder');
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->on($post)->to($account);

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->assertSee('in a context')
        ->fillForm(['roles' => []])
        ->call('save');

    expect(assignedCount())->toBe(1);
});

test('an assignment held elsewhere is drawn locked too, and never written over', function (): void {
    $user = signIn();
    Warden::allow($user)->to('update', roleClass());

    $account = makeUser('Holder');
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Warden::tenant()->onceTo(5, function () use ($account): void {
        livewire(AccountHost::class, ['accountKey' => $account->getKey()])
            ->assertSee('outside the tenant you are in')
            ->fillForm(['roles' => []])
            ->call('save');
    });

    expect(assignedCount())->toBe(1);
});

test('a save leaves alone the role somebody else handed out while this screen was open', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $mine = makeRole('mine');
    $theirs = makeRole('theirs');

    $screen = livewire(AccountHost::class, ['accountKey' => $account->getKey()]);

    $screen->set('data.roles', [$mine->getKey()]);

    // Somebody else, in another request, while this screen sits open.
    Warden::assign($theirs)->to($account);

    $screen->call('save');

    expect(Assignment::of($account))->toContain($theirs->getKey())
        ->and(Assignment::of($account))->toContain($mine->getKey());
});

test('a save leaves alone the role somebody else took back', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    $screen = livewire(AccountHost::class, ['accountKey' => $account->getKey()]);

    Warden::retract($role)->from($account);

    $screen->call('save');

    expect(Assignment::of($account))->toBeEmpty();
});

test('the field re-reads the store after a save, so the next one does not collide again', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    $screen = livewire(AccountHost::class, ['accountKey' => $account->getKey()]);

    Warden::assign($role)->to($account);

    $screen->call('save');

    $key = $role->getKey();

    expect($screen->get('data.roles'))->toContain(is_int($key) || is_string($key) ? (string) $key : '')
        ->and($screen->get('data.'.RoleAssignment::BASELINE))->toContain($role->getKey());
});

test('a page keeping its state somewhere other than data gets no baseline, and saves as before', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    livewire(AccountHostElsewhere::class, ['accountKey' => $account->getKey()])
        ->set('elsewhere.roles', [])
        ->call('save');

    expect(Assignment::of($account))->toBeEmpty();
});

test('a baseline that is not a list is read as no baseline at all', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->set('data.'.RoleAssignment::BASELINE, 'not a list')
        ->set('data.roles', [])
        ->call('save');

    expect(Assignment::of($account))->toBeEmpty();
});
