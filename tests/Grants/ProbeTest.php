<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Grants\Cause;
use ElPandaPe\FilamentWarden\Grants\Probe;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class);

function probedPermission(?string $name = null): Model
{
    return latestPermission($name);
}

test('an account that holds it is told so, and by which permission', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    $probe = Probe::run($user, probedPermission('viewAny'));

    expect($probe->verdict)->toBe(Stance::Granted)
        ->and($probe->cause)->toBe(Cause::GrantedDirectly)
        ->and($probe->permission)->not->toBeNull()
        ->and($probe->summary)->toContain((string) $probe->permission);
});

test('a denial is answered as a denial, not as an absence', function (): void {
    $user = makeUser();

    Warden::forbid($user)->to('viewAny', Post::class);

    expect(Probe::run($user, probedPermission('viewAny'))->verdict)->toBe(Stance::Forbidden);
});

test('an account with nothing is told that warden abstains', function (): void {
    $user = makeUser();
    $permission = makePermission('viewAny');

    $probe = Probe::run($user, $permission);

    expect($probe->verdict)->toBe(Stance::Abstain)
        ->and($probe->cause)->toBe(Cause::NoMatchingGrant);
});

test('a permission held through a role names the role', function (): void {
    $user = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::assign($role)->to($user);

    $probe = Probe::run($user, probedPermission('viewAny'));

    expect($probe->cause)->toBe(Cause::GrantedViaRole)
        ->and($probe->role)->not->toBeNull();
});

test('a narrowed rule asked about the class says why it cannot answer', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('update', Post::class)->where('title', 'alpha');

    $probe = Probe::run($user, probedPermission('update'));

    expect($probe->verdict)->toBe(Stance::Abstain)
        ->and($probe->note)->not->toBeNull()
        ->and($probe->note)->toContain('needs a record in front of it');
});

test('the same rule with the right record in front of it grants', function (): void {
    $user = makeUser();
    $alpha = Post::query()->create(['title' => 'alpha']);

    Warden::allow($user)->to('update', Post::class)->where('title', 'alpha');

    $probe = Probe::run($user, probedPermission('update'), recordKey($alpha));

    expect($probe->verdict)->toBe(Stance::Granted)
        ->and($probe->note)->toBeNull();
});

test('the same rule with the wrong record does not', function (): void {
    $user = makeUser();
    $beta = Post::query()->create(['title' => 'beta']);

    Warden::allow($user)->to('update', Post::class)->where('title', 'alpha');

    expect(Probe::run($user, probedPermission('update'), recordKey($beta))->verdict)->toBe(Stance::Abstain);
});

test('a key that names no row is said out loud, not answered around', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    $probe = Probe::run($user, probedPermission('viewAny'), 9999);

    expect($probe->cause)->toBe(Cause::NotApplicable)
        ->and($probe->summary)->toContain('has that key')
        ->and($probe->verdict)->toBe(Stance::Abstain);
});

test('a permission with no model is asked without one', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('export-reports');

    expect(Probe::run($user, probedPermission('export-reports'))->verdict)->toBe(Stance::Granted);
});

test('an entity type that no longer resolves is said out loud', function (): void {
    $user = makeUser();
    $permission = makePermission('viewAny');
    $permission->update(['entity_type' => 'gone.away']);

    $probe = Probe::run($user, $permission);

    expect($probe->summary)->toContain('no longer resolves')
        ->and($probe->cause)->toBe(Cause::NotApplicable);
});

test('a row with no name has no question to answer', function (): void {
    $user = makeUser();
    $permission = new (Context::resolve()->permissionClass())();

    expect(Probe::run($user, $permission)->summary)->toContain('no name');
});

test('the wildcard answers for anything asked of it', function (): void {
    $user = makeUser();

    Warden::allow($user)->everything();

    $permission = probedPermission('*');

    expect(Probe::run($user, $permission)->verdict)->toBe(Stance::Granted);
});

test('a record put in front of a permission with no model is a different question', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('export-reports');

    $probe = Probe::run($user, probedPermission('export-reports'), 1);

    expect($probe->summary)->toContain('no model behind it')
        ->and($probe->cause)->toBe(Cause::NotApplicable);
});

test('a permission with no title is named by its name', function (): void {
    config()->set('warden.titles.autogenerate', false);

    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    expect(Probe::run($user, probedPermission('viewAny'))->permission)->toBe('viewAny');
});
