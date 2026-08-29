<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostAction;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostElsewhere;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostFlat;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostProtected;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\AccountHostRepeater;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/**
 * The two halves of this field's state hold different types, and a test that
 * compares them strictly has to know it. No browser is involved: both are
 * written by the same `fillFrom()` call, one line apart. The list goes through
 * `CheckboxList`'s own `OptionsArrayStateCast`, whose `set()` runs `strval()`
 * over every item, so `data.roles` holds STRINGS; the baseline is written beside
 * it and bypasses that cast, so it keeps the store's INTEGERS.
 *
 * By the time `Assignment::apply()` reads them both are integers again, because
 * the same cast's `get()` converts numeric strings back. So `wants()`'s casting
 * is not what reconciles these two — it is belt and braces for a key that came
 * from somewhere else, as its own docblock says.
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

    // The field speaks for itself here: the form belongs to the application, so
    // there is no notification of ours to replace.
    $screen->assertNotified(__('filament-warden::ui.relations.roles.concurrent.kept_title'));

    expect(Assignment::of($account))->toContain($theirs->getKey())
        ->and(Assignment::of($account))->toContain($mine->getKey());
});

test('nothing is announced when the save is rolled back', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $mine = makeRole('mine');
    $theirs = makeRole('theirs');

    $screen = livewire(AccountHost::class, ['accountKey' => $account->getKey()]);

    $screen->set('data.roles', [$mine->getKey()]);

    Warden::assign($theirs)->to($account);

    $connection = DB::connection(Context::resolve()->connection());

    expect(fn () => $connection->transaction(function () use ($screen): void {
        $screen->call('save');

        throw new RuntimeException('a failure the field cannot see coming');
    }))->toThrow(RuntimeException::class);

    $screen->assertNotNotified(__('filament-warden::ui.relations.roles.concurrent.kept_title'));
});

test('a save that met nobody says nothing', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->set('data.roles', [$role->getKey()])
        ->call('save')
        ->assertNotNotified(__('filament-warden::ui.relations.roles.concurrent.kept_title'));

    expect(Assignment::of($account))->toContain($role->getKey());
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

    // The bag is a map keyed by each field's own state path, so two rows of a
    // repeater get a copy each rather than one between them.
    $bag = $screen->get('data.'.RoleAssignment::BASELINE);
    $bag = is_array($bag) ? $bag : [];

    expect($screen->get('data.roles'))->toContain(is_int($key) || is_string($key) ? (string) $key : '')
        ->and($bag['data.roles'] ?? null)->toContain($role->getKey());
});

test('a page that calls its state something other than data is protected just the same', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $mine = makeRole('mine');
    $theirs = makeRole('theirs');

    $screen = livewire(AccountHostElsewhere::class, ['accountKey' => $account->getKey()]);

    $screen->set('elsewhere.roles', [$mine->getKey()]);

    Warden::assign($theirs)->to($account);

    $screen->call('save');

    expect(Assignment::of($account))->toContain($theirs->getKey())
        ->and(Assignment::of($account))->toContain($mine->getKey());
});

test('a page keeping a protected property of the same name still opens', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    $key = $role->getKey();

    $screen = livewire(AccountHostProtected::class, ['accountKey' => $account->getKey()]);

    // `assertOk()` is declared as returning a TestResponse, so the chain stops
    // here whatever it returns at runtime (AGENTS.md §6.12).
    $screen->assertOk();

    $screen->assertSet('elsewhere.roles', [is_int($key) || is_string($key) ? (string) $key : '']);

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

test('a page with no state path of its own gets no baseline, and saves as before', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    $screen = livewire(AccountHostFlat::class, ['accountKey' => $account->getKey()]);

    // What the branch decides: with nothing to sit beside, the copy is not
    // written INTO the list itself. A save alone cannot tell the two apart —
    // both shapes end with the role retracted.
    expect($screen->get('roles'))->not->toHaveKey(RoleAssignment::BASELINE);

    $screen->set('roles', [])->call('save');

    expect(Assignment::of($account))->toBeEmpty();
});

test('the field inside an action modal leaves the mounted actions array alone', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    $screen = livewire(AccountHostAction::class, ['accountKey' => $account->getKey()]);

    $screen->call('mountAction', 'roles');

    $page = $screen->instance();

    // Filament indexes this array by position, and resolves the mounted
    // schema's name from its last key.
    expect($page instanceof AccountHostAction ? $page->mountedKeys() : null)->toBe([0])
        ->and($screen->get('mountedActions.0.data.'.RoleAssignment::BASELINE))->toBeArray();
});

test('the copy never reaches the data a repeating container is saved with', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();

    $screen = livewire(AccountHostRepeater::class, ['accountKey' => $account->getKey()]);

    $page = $screen->instance();
    $state = $page instanceof AccountHostRepeater ? $page->saved() : [];

    // A repeater validates on its container, so Laravel hands back the whole
    // item as validated data and the prune keeps anything found inside it.
    // Anything the field left in an item would ride into `$record->update()`.
    expect($state)->toBe(['rows' => [[], []]]);
});

test('two rows of a repeater get a copy each, not one between them', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();

    $screen = livewire(AccountHostRepeater::class, ['accountKey' => $account->getKey()]);

    $bag = $screen->get('data.'.RoleAssignment::BASELINE);

    expect($bag)->toBeArray()->toHaveCount(2);
});

test('what the account save did stays reachable in the container, the same recipe the grid offers', function (): void {
    $signedIn = signIn();
    Warden::allow($signedIn)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole('editor');

    livewire(AccountHost::class, ['accountKey' => $account->getKey()])
        ->fillForm(['roles' => [$role->getKey()]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Assignment::of($account))->toContain($role->getKey())
        ->and(app()->bound(SaveReport::class))->toBeTrue();

    $report = app(SaveReport::class);

    // All zero because `AccountHost::save()` saves relationships twice and the
    // first pass re-fills the field from the store, exactly as `GridHost` does
    // — the counts are the host's, not this screen's. What is being pinned is
    // that a report is REACHABLE, so a page this package does not own can say
    // more than the field's one notification. The three stance counts stay at
    // zero on this screen whatever it wrote: a role is held or it is not.
    expect($report->written)->toBe(0)
        ->and($report->preserved)->toBe(0)
        ->and($report->refused)->toBeEmpty()
        ->and($report->granted)->toBe(0)
        ->and($report->forbidden)->toBe(0)
        ->and($report->revoked)->toBe(0);
});
