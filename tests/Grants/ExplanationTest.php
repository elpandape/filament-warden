<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Grants\Cause;
use ElPandaPe\FilamentWarden\Grants\Explanation;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class);

function postCatalog(): Catalog
{
    return Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));
}

function entryFor(string $action): Entry
{
    foreach (postCatalog()->entries as $entry) {
        if ($entry->model === Post::class && $entry->name === $action) {
            return $entry;
        }
    }

    throw new RuntimeException("No entry [{$action}].");
}

/**
 * @param  array<string, array<string, bool>>  $narrowed
 */
function why(Model $role, string $action, array $narrowed = [], ?Stance $onScreen = null, ?Stance $stored = null): Explanation
{
    return Explanation::of($role, entryFor($action), Post::class, $action, $narrowed, $onScreen, $stored);
}

test('a permission the role holds itself says so, and names it', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny');

    expect($explanation->verdict)->toBe(Stance::Granted)
        ->and($explanation->cause)->toBe(Cause::GrantedDirectly)
        ->and($explanation->permission)->not->toBeNull()
        ->and($explanation->summary)->toContain($explanation->permission)
        ->and($explanation->role)->toBeNull();
});

test('an explicit denial says explicitly forbidden, not merely not allowed', function (): void {
    $role = makeRole();

    Warden::forbid($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny');

    expect($explanation->verdict)->toBe(Stance::Forbidden)
        ->and($explanation->cause)->toBe(Cause::ForbiddenDirectly)
        ->and($explanation->summary)->toContain('Explicitly forbidden');
});

test('abstaining and forbidding are two different answers', function (): void {
    $role = makeRole();

    $abstained = why($role, 'viewAny');

    Warden::forbid($role)->to('viewAny', Post::class);

    $forbidden = why($role, 'viewAny');

    expect($abstained->verdict)->toBe(Stance::Abstain)
        ->and($abstained->cause)->toBe(Cause::NoMatchingGrant)
        ->and($abstained->permission)->toBeNull()
        ->and($abstained->summary)->not->toBe($forbidden->summary);
});

test('a permission given to everyone says so', function (): void {
    $role = makeRole();

    Warden::allowEveryone()->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->cause)->toBe(Cause::GrantedToEveryone);
});

test('a denial applied to everyone says so too', function (): void {
    $role = makeRole();

    Warden::forbidEveryone()->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->cause)->toBe(Cause::ForbiddenToEveryone);
});

test('a role that holds another role is explained through it, and it is named', function (): void {
    $role = makeRole('editor');
    $inherited = makeRole('reader');

    Warden::allow($inherited)->to('viewAny', Post::class);
    Warden::assign($inherited)->to($role);

    $explanation = why($role, 'viewAny');

    expect($explanation->cause)->toBe(Cause::GrantedViaRole)
        ->and($explanation->role)->not->toBeNull()
        ->and($explanation->summary)->toContain($explanation->role);
});

test('a narrowed cell says why nothing matched, which explain cannot', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', Post::class)->where('id', 1);

    $stored = RoleGrants::of($role, postCatalog());
    $explanation = why($role, 'update', $stored->narrowed());

    expect($explanation->cause)->toBe(Cause::NoMatchingGrant)
        ->and($explanation->narrowed)->not->toBeNull()
        ->and($explanation->narrowed)->toContain('with a record in front of it');
});

test('a cell with no narrowed rule says nothing about conditions', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->narrowed)->toBeNull();
});

test('a stance changed on screen and not saved is called out', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $explanation = why($role, 'viewAny', [], Stance::Abstain, Stance::Granted);

    expect($explanation->pending)->not->toBeNull()
        ->and($explanation->pending)->toContain('abstains');
});

test('a stance that matches the store is not called out', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny', [], Stance::Granted, Stance::Granted)->pending)->toBeNull();
});

test('the payload the browser paints carries every line already written', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(array_keys(why($role, 'viewAny')->toPayload()))
        ->toBe(['verdict', 'cause', 'summary', 'permission', 'role', 'narrowed', 'pending']);
});

test('every cause the package mirrors is one warden can produce', function (): void {
    foreach (ElPandaPe\Warden\Checks\Explain\Cause::cases() as $case) {
        expect(Cause::of($case)->value)->toBe($case->value);
    }

    expect(Cause::cases())->toHaveSameSize(ElPandaPe\Warden\Checks\Explain\Cause::cases());
});

test('a permission with no title is named by its name', function (): void {
    config()->set('warden.titles.autogenerate', false);

    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    expect(why($role, 'viewAny')->permission)->toBe('viewAny');
});
