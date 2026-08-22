<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Audit;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\BrokenPolicyResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\ClosureGroupResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\CommentResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\LedgerResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\TagResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Ledger;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

/**
 * `warden:clean` deletes every unused permission, declared or not, so both of the
 * lists this file separates are true about it. What separates them is the exit
 * code: the informational one is what normal use of the grid produces —
 * `revoke()` deletes the grant and leaves the row — so a build that went red on it
 * would go red on every save, forever.
 *
 * Three shapes cannot be asked whether the catalogue declares them, and all three
 * land informational: a row clamped to one record (the catalogue holds classes,
 * never rows), the wildcard, and a name that is not a string. Only the first two
 * can be written by a test; the third is unreachable, because `name` is `NOT NULL`.
 */
pest()->extend(TestCase::class);

function audit(bool $check = false): int
{
    return Artisan::call('filament-warden:audit', $check ? ['--check' => true] : []);
}

/**
 * The last command's output with every run of whitespace flattened.
 *
 * The console view word-wraps a heading at the terminal width, so a sentence that
 * is one line in the language file is not one line on screen. `Artisan::output()`
 * fetches from a buffered output and empties it, so it answers once per run.
 */
function auditOutput(): string
{
    return (string) preg_replace('/\s+/', ' ', Artisan::output());
}

test('a clean installation answers zero, with or without the flag', function (): void {
    expect(Audit::run()->isClean())->toBeTrue()
        ->and(audit())->toBe(0)
        ->and(audit(true))->toBe(0);
});

test('the flag is what turns a finding into a red build', function (): void {
    makePermission('nobody-holds-this');

    expect(Audit::run()->forgotten)->not->toBeEmpty()
        ->and(audit())->toBe(0)
        ->and(audit(true))->toBe(1);

    expect(auditOutput())->toContain('Permissions nothing declares');
});

test('a permission somebody holds is in neither list', function (): void {
    Warden::allow(makeRole())->to('viewAny', roleClass());

    $audit = Audit::run();

    expect($audit->orphans)->toBeEmpty()
        ->and($audit->forgotten)->toBeEmpty();
});

test('a cell turned off leaves a row the catalogue still declares, and it is not a red build', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());
    Warden::disallow($role)->to('viewAny', roleClass());

    $audit = Audit::run();

    expect($audit->orphans)->toContain('viewAny on warden.role')
        ->and($audit->forgotten)->toBeEmpty()
        ->and($audit->isClean())->toBeTrue()
        ->and(audit(true))->toBe(0);
});

test('a permission clamped to one record is not called undeclared', function (): void {
    $post = Post::query()->create(['title' => 'A post']);

    $permission = Warden::permission([
        'name' => 'nothing-declares-this',
        'entity_type' => $post->getMorphClass(),
        'entity_id' => $post->getKey(),
    ]);
    $permission->save();

    $audit = Audit::run();

    expect($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->not->toBeEmpty()
        ->and(audit(true))->toBe(0);
});

test('a record-pinned row whose name the catalogue does declare is informational too', function (): void {
    $role = makeRole();

    $permission = Warden::permission([
        'name' => 'viewAny',
        'entity_type' => $role->getMorphClass(),
        'entity_id' => $role->getKey(),
    ]);
    $permission->save();

    $audit = Audit::run();

    expect($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->toContain('viewAny on warden.role')
        ->and(audit(true))->toBe(0);
});

test('the widest rule in the store is not a red build when nobody holds it', function (): void {
    $permission = Warden::permission(['name' => '*', 'entity_type' => '*']);
    $permission->save();

    $audit = Audit::run();

    expect($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->not->toBeEmpty()
        ->and(audit(true))->toBe(0);
});

test('a name one panel declares is not undeclared because another panel does not', function (): void {
    $post = new Post();
    $tag = new Tag();

    $onPosts = Warden::permission(['name' => 'viewAny', 'entity_type' => $post->getMorphClass()]);
    $onPosts->save();

    $onTags = Warden::permission(['name' => 'viewAny', 'entity_type' => $tag->getMorphClass()]);
    $onTags->save();

    $audit = Audit::of([
        Panel::make()->id('posts')->resources([PostResource::class]),
        Panel::make()->id('tags')->resources([TagResource::class]),
    ]);

    expect($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->toContain('viewAny on '.Post::class)
        ->and($audit->orphans)->toContain('viewAny on '.Tag::class);
});

test('the informational list is printed, and it does not answer nothing to report', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());
    Warden::disallow($role)->to('viewAny', roleClass());

    audit();

    expect(auditOutput())->toContain('Permissions no grant points at.')
        ->toContain('viewAny on warden.role')
        ->not->toContain('Nothing to report.');
});

test('a run with nothing at all still answers nothing to report', function (): void {
    audit();

    expect(auditOutput())->toContain('Nothing to report.');
});

test('a grant for an action nothing declares is the silent typo', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viwAny', roleClass());

    expect(Audit::run()->strays)->toContain('viwAny on warden.role');
});

test('a grant for an action a policy does declare is not', function (): void {
    Warden::allow(makeRole())->to('viewAny', roleClass());

    expect(Audit::run()->strays)->toBeEmpty();
});

test('a grant over one record is not a typo, because the catalogue holds classes', function (): void {
    $post = Post::query()->create(['title' => 'A post']);

    Warden::allow(makeRole())->to('nothing-declares-this', $post);

    expect(Audit::run()->strays)->toBeEmpty();
});

test('a rule carrying conditions is not a typo either', function (): void {
    Warden::allow(makeRole())->to('viewAny', roleClass())->where('name', 'editor');

    expect(Audit::run()->strays)->toBeEmpty();
});

test('the widest rule in the store is not a mistake', function (): void {
    Warden::allow(makeRole())->everything();

    expect(Audit::run()->strays)->toBeEmpty()
        ->and(Audit::run()->drifted)->toBeEmpty();
});

test('a whole entity type nobody declares is drift, and it is reported apart', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'viewAny')
        ->update(['entity_type' => 'gone.away']);

    $audit = Audit::run();

    expect($audit->drifted)->toBe(['gone.away'])
        ->and($audit->strays)->toBeEmpty();
});

test('a resource whose model has no policy is named', function (): void {
    expect(Audit::run()->unpoliced)->toBeEmpty();
});

test('the command is registered, so it can be reached at all', function (): void {
    expect(array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('filament-warden:audit');
});

test('a resource whose model has no policy is the case filament fails open on', function (): void {
    $panel = Panel::make()->id('scratch')->resources([CommentResource::class]);

    expect(Audit::of([$panel])->unpoliced)->toHaveCount(1)
        ->and(Audit::of([$panel])->unpoliced[0])->toContain('has no policy')
        ->and(Audit::of([$panel])->unpoliced[0])->toContain('does not fail closed');
});

test('a strict panel is not told it fails open, because it does not', function (): void {
    $panel = Panel::make()->id('scratch')->strictAuthorization()->resources([CommentResource::class]);

    expect(Audit::of([$panel])->unpoliced[0])->not->toContain('does not fail closed');
});

test('a resource pointing at a class nobody wrote is named, not swallowed', function (): void {
    $ghost = new class extends Resource {};

    $panel = Panel::make()->id('scratch')->resources([$ghost::class]);

    expect(Audit::of([$panel])->unpoliced[0])->toContain('does not exist');
});

test('a policy that cannot be built is a finding, not a fatal', function (): void {
    Gate::policy(Ledger::class, 'App\\Policies\\NotThere');

    $panel = Panel::make()->id('scratch')->resources([BrokenPolicyResource::class]);

    expect(Audit::of([$panel])->unpoliced[0])->toContain('could not be built');
});

test('a policy that declares nothing is not the same finding as no policy', function (): void {
    Gate::policy(Ledger::class, new class
    {
        use Illuminate\Auth\Access\HandlesAuthorization;
    }::class);

    $panel = Panel::make()->id('scratch')->resources([BrokenPolicyResource::class]);

    expect(Audit::of([$panel])->unpoliced[0])->toContain('declares no action');
});

test('a relation manager that cannot be walked is named, with the line that settles it', function (): void {
    $panel = Panel::make()->id('scratch')->resources([LedgerResource::class]);

    $findings = Audit::of([$panel])->unwalkable;

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toContain('LedgerRelationManager')
        ->and($findings[0])->toContain('catalog.models');
});

test('a screen nobody guards reaches the audit, which is how the guard reaches CI', function (): void {
    $panel = Panel::make()->id('scratch')->pages([Reports::class]);

    expect(Audit::of([$panel])->open)->toBe(['scratch: '.Reports::class]);
});

test('a relation group built with a closure does not take the catalogue with it', function (): void {
    $panel = Panel::make()->id('scratch')->resources([ClosureGroupResource::class]);

    expect(Catalog::for($panel)->entries)->not->toBeEmpty()
        ->and(Audit::of([$panel])->unwalkable)->toBeEmpty();
});
