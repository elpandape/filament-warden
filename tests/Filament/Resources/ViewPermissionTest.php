<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Support\Facades\Auth;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function readablePermission(): string
{
    $user = signIn();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    // A row the test panel's own catalogue declares: the plugin registers the
    // role resource, so `viewAny` over a role is derived from its policy.
    Warden::allow(makeRole('editor'))->to('viewAny', roleClass());

    $row = permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'viewAny')
        ->orderByDesc('id')
        ->firstOrFail();

    return recordKey($row);
}

test('an authority the store never trusted with a permission does not read it', function (): void {
    signIn();

    livewire(ViewPermission::class, ['record' => makePermission('viewAny')->getKey()])->assertForbidden();
});

test('the screen names the permission, where it came from and how far it reaches', function (): void {
    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('viewAny')
        ->assertSee('From a policy')
        ->assertSee('Every row')
        ->assertOk();
});

test('a permission pinned to one record says so where the reach goes', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $post = Post::query()->create(['title' => 'A post']);
    Warden::allow(makeRole())->to('view', $post);

    $pinned = permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('entity_id')
        ->firstOrFail();

    livewire(ViewPermission::class, ['record' => $pinned->getKey()])
        ->assertSee('One record only')
        ->assertDontSee('Every row')
        ->assertOk();
});

test('who holds it arrives as counts, never as a list of names', function (): void {
    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertSee('Who holds it')
        ->assertDontSee('Editor')
        ->assertOk();
});

test('the test bench answers for the account it is asked about', function (): void {
    $key = readablePermission();

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    livewire(ViewPermission::class, ['record' => $key])
        ->callAction('probe', ['account' => $holder->getKey()])
        ->assertNotified();
});

test('the test bench is closed when the installation closed it', function (): void {
    config()->set('filament-warden.permissions.probe', false);

    $key = readablePermission();

    livewire(ViewPermission::class, ['record' => $key])
        ->assertActionDoesNotExist('probe')
        ->assertSee('Who holds it');
});

test('a stored rule is read out as it will be evaluated', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->to('update', Post::class)->where('title', 'alpha')->orWhere('id', '>=', 2);

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->assertSee('title = alpha or id &gt;= 2', escape: false)
        ->assertSee('With conditions');
});

test('a row that reaches both ways still reads its rule out', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->toOwn(Post::class, 'update')->where('title', '=', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->assertSee('title = alpha')
        ->assertSee('Cannot be read');
});

test('the account is searched by whatever column it can be recognised by', function (): void {
    signIn(makeUser('Signed In'));

    $amaru = makeUser('Amaru Quispe');
    makeUser('Someone Else');

    $found = ViewPermission::accounts('Amaru');

    expect($found)->toBe([recordKey($amaru) => 'Amaru Quispe'])
        ->and(ViewPermission::accountLabel($amaru->getKey()))->toBe('Amaru Quispe')
        ->and(ViewPermission::accountLabel('nope'))->toBeNull();
});

test('a permission with no model is asked without a record to put in front of it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $loose = makePermission('export-reports');

    livewire(ViewPermission::class, ['record' => $loose->getKey()])
        ->assertSee('None: a loose permission')
        ->assertActionExists('probe');
});

test('the wildcard reads as the wildcard, not as a class', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    Warden::allow(makeRole())->everything();

    $wildcard = permissionClass()::query()->withoutGlobalScopes()->where('entity_type', '*')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $wildcard->getKey()])
        ->assertSee('Any entity')
        ->assertSee('Wildcard');
});

test('the test bench puts the row it is given in front of the rule', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    $alpha = Post::query()->create(['title' => 'alpha']);

    Warden::allow($holder)->to('update', Post::class)->where('title', 'alpha');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->callAction('probe', ['account' => $holder->getKey(), 'record' => recordKey($alpha)])
        ->assertNotified();
});

test('an account key that names nobody answers nothing at all', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $permission = makePermission('export-reports');

    livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->callAction('probe', ['account' => 9999])
        ->assertNotNotified();
});

test('an explicit denial comes back as a denial', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::forbid($holder)->to('viewAny', roleClass());

    $row = permissionClass()::query()->withoutGlobalScopes()->where('name', 'viewAny')->orderByDesc('id')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $row->getKey()])
        ->callAction('probe', ['account' => $holder->getKey()])
        ->assertNotified('forbidden');
});

test('a grant comes back as a grant, which is the word a denial has to differ from', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', roleClass());

    livewire(ViewPermission::class, ['record' => latestPermission('viewAny')->getKey()])
        ->callAction('probe', ['account' => $holder->getKey()])
        ->assertNotified('granted');
});

test('a row nobody holds comes back as abstaining, which is neither of the two', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');

    livewire(ViewPermission::class, ['record' => makePermission('export-reports')->getKey()])
        ->callAction('probe', ['account' => $holder->getKey()])
        ->assertNotified('abstains');
});

test('an account nobody could name is nobody at all', function (): void {
    signIn();

    expect(ViewPermission::accountLabel(null))->toBeNull();
});

test('a guard that resolves no model offers no accounts to probe with', function (): void {
    signIn();

    config()->set('auth.providers.users', ['driver' => 'database', 'table' => 'users']);
    Auth::forgetGuards();

    expect(ViewPermission::accounts('anything'))->toBeEmpty();
});

test('the test bench says how far the permission reaches, when it can be counted', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    $holder = makeUser('Holder');

    Document::query()->create(['title' => 'One']);
    Document::query()->create(['title' => 'Two']);

    Warden::allow($holder)->to('view', Document::class)->where('title', 'One');

    $twin = permissionClass()::query()->withoutGlobalScopes()->whereNotNull('options')->firstOrFail();

    livewire(ViewPermission::class, ['record' => $twin->getKey()])
        ->callAction('probe', ['account' => $holder->getKey()])
        ->assertNotified();

    expect(ElPandaPe\FilamentWarden\Grants\Reach::of($twin, $holder)->sentence())
        ->toStartWith('It falls on 1 of 2 rows.');
});
