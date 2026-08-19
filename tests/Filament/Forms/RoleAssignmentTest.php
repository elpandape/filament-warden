<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

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
