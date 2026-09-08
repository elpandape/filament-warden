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

test('the rule the card prints is the twin that matched, not the row on screen', function (): void {
    $user = makeUser();
    $alpha = Post::query()->create(['title' => 'alpha']);

    // Two rows of the catalogue with the same name and entity: the plain one
    // and the narrowed twin the `where()` mints. The plain one is what a
    // listing hands to this screen, and the twin is what answers — so a card
    // that read the rule off its own record would print nothing beside a
    // verdict that a condition decided.
    $plain = makePermission('update');
    $plain->forceFill(['entity_type' => Post::class])->save();

    Warden::allow($user)->to('update', Post::class)->where('title', '=', 'alpha');

    $probe = Probe::run($user, $plain, recordKey($alpha));

    expect($probe->verdict)->toBe(Stance::Granted)
        ->and($probe->rule)->not->toBeNull()
        ->and($probe->rule)->toContain('title');
});

test('a rule with no conditions has no rule to print', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    expect(Probe::run($user, probedPermission('viewAny'))->rule)->toBeNull();
});

test('an assignment tied to a context says so beside the role', function (): void {
    $user = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'section']);

    Warden::allow($role)->to('view', Post::class);
    Warden::assign($role)->on($post)->to($user);

    // The record is not decoration here: `Explainer::source()` only counts a
    // restricted assignment when the check has a MODEL in front of it and that
    // model belongs to the context, so asked about the class warden names no
    // role at all and this sentence has nothing to attach to. Probing the row
    // the assignment is tied to is the only way the restricted branch is
    // reachable at all.
    $probe = Probe::run($user, probedPermission('view'), recordKey($post));

    // The restriction is the half that makes the panel and `whereCan()`
    // disagree about this account — so it is said, not left for somebody to
    // find.
    expect($probe->via)->not->toBeNull()
        ->and($probe->via)->toContain('Editor')
        ->and($probe->via)->toContain('tied to');
});

test('an unrestricted assignment wins over a restricted one of the same role', function (): void {
    $user = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'section']);

    Warden::allow($role)->to('view', Post::class);
    Warden::assign($role)->to($user);
    Warden::assign($role)->on($post)->to($user);

    // Two rows, same role, and both usable for this question. Ordering the
    // unrestricted one first is what keeps the card from warning about a
    // restriction that is not in the way: that row answers with nothing in
    // front of it, so the disagreement the other sentence describes does not
    // apply to this account.
    expect(Probe::run($user, probedPermission('view'), recordKey($post))->via)->not->toContain('tied to');
});

test('a direct grant with no role says nothing about how it was reached', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    expect(Probe::run($user, probedPermission('viewAny'))->via)->toBeNull();
});

test('a grant that ends says when, and where the date is set', function (): void {
    $user = makeUser();

    Warden::allow($user)->until(now()->addWeeks(2))->to('viewAny', Post::class);

    $probe = Probe::run($user, probedPermission('viewAny'));

    expect($probe->verdict)->toBe(Stance::Granted)
        ->and($probe->until)->not->toBeNull()
        ->and($probe->until)->toContain('The grant that answered ends on');
});

test('a grant that does not end says nothing about time', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('viewAny', Post::class);

    expect(Probe::run($user, probedPermission('viewAny'))->until)->toBeNull();
});

test('the assignment is named when it lapses before the grant does', function (): void {
    $user = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->until(now()->addMonths(2))->to('viewAny', Post::class);
    Warden::assign($role)->until(now()->addWeek())->to($user);

    // Two rows can end this answer and the earlier one is the horizon. Naming
    // which is not decoration: a grant date is moved from the role's grid and
    // an assignment date from the account, so the sentence decides where
    // somebody goes to change it.
    expect(Probe::run($user, probedPermission('viewAny'))->until)
        ->toContain('The assignment that reaches it ends on');
});

test('the grant is named when it lapses first, even reached through a role', function (): void {
    $user = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->until(now()->addWeek())->to('viewAny', Post::class);
    Warden::assign($role)->until(now()->addMonths(2))->to($user);

    expect(Probe::run($user, probedPermission('viewAny'))->until)
        ->toContain('The grant that answered ends on');
});

test('a grant to everybody that ends is read from the row that has no authority', function (): void {
    $user = makeUser();

    Warden::allowEveryone()->until(now()->addWeeks(3))->to('viewAny', Post::class);

    $probe = Probe::run($user, probedPermission('viewAny'));

    expect($probe->cause)->toBe(Cause::GrantedToEveryone)
        ->and($probe->until)->toContain('The grant that answered ends on');
});
