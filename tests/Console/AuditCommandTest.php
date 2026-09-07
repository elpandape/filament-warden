<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Audit;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\RelationManagers\RolesRelationManager;
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Support\Config;
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
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

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
 *
 * "The wildcard" is two independent terms in `Audit::orphans()` — `$name === '*'`
 * and `$type === '*'` — and they skip two different shapes: `everything()` sets
 * both at once, but the grid's MANAGE column mints only the first half (a `*`
 * name on a real model), and `to($name, '*')` writes only the second half (a
 * real name on the wildcard entity type). A row with both cannot tell either
 * term apart from the disjunction; the tests below each write only one half.
 *
 * The `test` panel registers no resource for `Post`, so a grant naming its class
 * is itself undeclared and lands in `drifted` — a build failure the assertion on
 * `isClean()` would otherwise blame on the stranded grant it is not testing for.
 * `catalog.models` exists for exactly this: a model with a policy and no resource.
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
        ->and(audit(true))->toBe(1)
        ->and(auditOutput())->toContain('Permissions nothing declares');
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

test('the wildcard name on a real model is not a red build either', function (): void {
    $permission = Warden::permission(['name' => '*', 'entity_type' => new Post()->getMorphClass()]);
    $permission->save();

    $audit = Audit::run();

    expect($audit->forgotten)->toBeEmpty()
        ->and($audit->orphans)->not->toBeEmpty()
        ->and(audit(true))->toBe(0);
});

test('the wildcard entity type on a real action is not a red build either', function (): void {
    $permission = Warden::permission(['name' => 'viewAny', 'entity_type' => '*']);
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

test('the informational bucket prints as INFO, never as WARN', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());
    Warden::disallow($role)->to('viewAny', roleClass());

    audit();

    expect(auditOutput())->toContain('INFO')
        ->not->toContain('WARN');
});

test('a red bucket prints as WARN, never as INFO', function (): void {
    makePermission('nobody-holds-this');

    audit();

    expect(auditOutput())->toContain('WARN')
        ->not->toContain('INFO');
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

test('a resource with no policy turns the build red through the command, not only through the class', function (): void {
    Filament::getPanel('test')->resources([CommentResource::class]);

    $audit = Audit::run();

    expect($audit->unpoliced)->toHaveCount(1)
        ->and($audit->unpoliced[0])->toContain('has no policy')
        ->and($audit->unpoliced[0])->not->toContain('does not fail closed')
        ->and(audit())->toBe(0)
        ->and(audit(true))->toBe(1);
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

test('a relation manager that cannot be walked is named, and says what would settle it', function (): void {
    $panel = Panel::make()->id('scratch')->resources([LedgerResource::class]);

    $findings = Audit::of([$panel])->unwalkable;

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toContain('LedgerRelationManager')
        ->and($findings[0])->toContain('$relatedResource');
});

test('declaring the model in catalog.models does not clear the line, whatever the line says', function (): void {
    $panel = Panel::make()->id('scratch')->resources([LedgerResource::class]);

    config()->set('filament-warden.catalog.models', [Post::class]);

    expect(Audit::of([$panel])->unwalkable)->toHaveCount(1);
});

test('wiring this package own relation manager, as the readme says to, leaves check green', function (): void {
    $owner = new class extends Resource
    {
        protected static ?string $model = Post::class;

        public static function getRelations(): array
        {
            return [RolesRelationManager::class];
        }
    };

    Filament::getPanel('test')->resources([$owner::class]);

    $audit = Audit::run();

    expect($audit->unwalkable)->toContain(
        RolesRelationManager::class.': it declares no $relatedResource, so the walk stops here'
            .' — `catalog.models` puts the model in the catalogue, it does not clear this line',
    )
        ->and($audit->isSilent())->toBeFalse()
        ->and(audit(check: true))->toBe(0);
});

test('--panel audits the one named and leaves the others out of it', function (): void {
    Filament::getPanel('test')->resources([BrokenPolicyResource::class]);

    // The finding belongs to `test`, so asking for `bare` has to come back with
    // nothing — and asking for nothing at all has to still find it.
    expect(Audit::of([Filament::getPanel('bare')])->unpoliced)->toBeEmpty()
        ->and(Audit::run()->unpoliced)->not->toBeEmpty();

    expect(Artisan::call('filament-warden:audit', ['--panel' => 'bare', '--check' => true]))->toBe(0)
        ->and(Artisan::call('filament-warden:audit', ['--check' => true]))->toBe(1);
});

test('--panel with a name no panel answers to fails rather than auditing everything', function (): void {
    Filament::getPanel('test')->resources([BrokenPolicyResource::class]);

    expect(Artisan::call('filament-warden:audit', ['--panel' => 'nowhere', '--check' => true]))->toBe(1)
        ->and(auditOutput())->toContain((string) __('filament-warden::ui.console.audit.unknown_panel', ['panel' => 'nowhere']));
});

test('a config entry this package cannot use turns the build red, in all four keys', function (): void {
    config()->set('filament-warden.catalog.models', ['App\\Models\\Gone']);

    expect(audit(check: true))->toBe(1)
        ->and(Audit::run()->misconfigured)->toHaveCount(1);

    config()->set('filament-warden.catalog.models', []);
    config()->set('filament-warden.catalog.custom', ['export' => 42]);

    expect(audit(check: true))->toBe(1);

    config()->set('filament-warden.catalog.custom', []);
    config()->set('filament-warden.catalog.scopes', ['read' => 'viewAny']);

    expect(audit(check: true))->toBe(1);

    config()->set('filament-warden.catalog.scopes', []);
    config()->set('filament-warden.guard.panel', ['test' => '']);

    expect(audit(check: true))->toBe(1);
});

test('a scope no column of the grid answers to is reported, because it is filed not dropped', function (): void {
    // The other four vanish. This one SURVIVES `Config::custom()` — it is a
    // string, which is all that filter asks — and `Catalog::fromCustom()` then
    // falls back to `Scope::Write` one layer down. A permission under the wrong
    // heading is worse than one that never appeared, so it says so separately.
    config()->set('filament-warden.catalog.custom', ['export' => 'reed']);

    expect(Config::custom())->toBe(['export' => 'reed'])
        ->and(audit(check: true))->toBe(1)
        ->and(Audit::run()->misconfigured[0])->toContain('filed under write instead');
});

test('a panel that never registered this package is not told its screens are open', function (): void {
    $asked = Panel::make()->id('asked')->plugin(FilamentWardenPlugin::make())->pages([Reports::class]);
    $never = Panel::make()->id('never')->pages([Reports::class]);

    // The same screen, on two panels. Only the one that asked for this package
    // hears about it — and a build that never installed the grid does not go red
    // over Filament's own default answer.
    expect(Audit::of([$asked])->open)->toBe(['asked: '.Reports::class])
        ->and(Audit::of([$never])->open)->toBeEmpty()
        ->and(Audit::of([$never])->isClean())->toBeTrue();
});

test('a screen nobody guards reaches the audit, which is how the guard reaches CI', function (): void {
    // The plugin is registered on purpose, and the test would be empty without
    // it: since `2.3.0` this bucket only speaks for panels that asked for it,
    // because a screen with no `canAccess()` is open by Filament's own default
    // and reporting one on a panel that never registered this package turns
    // somebody else's build red over a decision this package was not asked to make.
    $panel = Panel::make()->id('scratch')->plugin(FilamentWardenPlugin::make())->pages([Reports::class]);

    expect(Audit::of([$panel])->open)->toBe(['scratch: '.Reports::class]);
});

test('a relation group built with a closure does not take the catalogue with it', function (): void {
    $panel = Panel::make()->id('scratch')->resources([ClosureGroupResource::class]);

    expect(Catalog::for($panel)->entries)->not->toBeEmpty()
        ->and(Audit::of([$panel])->unwalkable)->toBeEmpty();
});

test('a grant whose authority no longer exists is reported', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    Context::resolve()->roleClass()::query()->whereKey($role->getKey())->delete();

    expect(Audit::run()->stranded)->not->toBeEmpty();
});

test('a stranded grant never turns a build red', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    Context::resolve()->roleClass()::query()->whereKey($role->getKey())->delete();

    $audit = Audit::run();

    expect($audit->stranded)->not->toBeEmpty()
        ->and($audit->isClean())->toBeTrue()
        ->and($audit->isSilent())->toBeFalse();

    /** @var Illuminate\Testing\PendingCommand $pending */
    $pending = $this->artisan('filament-warden:audit', ['--check' => true]);

    $pending->assertExitCode(0);
});

test('a grant whose authority is alive is not reported', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    expect(Audit::run()->stranded)->toBeEmpty();
});

test('a grant behind a stray morph alias is reported here, because drifted cannot see it', function (): void {
    Warden::allow(makeRole())->to('viewAny', roleClass());

    Context::resolve()->grantClass()::query()->withoutGlobalScopes()->update(['entity_type' => 'gone.away']);

    expect(Audit::run()->stranded)->toBe(['gone.away']);
});

test('a catalogue still in its pre-2.0 shape turns the build red before anybody writes to it', function (): void {
    $table = new (Context::resolve()->permissionClass())()->getTable();

    // The index goes first: sqlite rebuilds the table to drop a column and
    // refuses while an index still names it.
    Schema::table($table, static function (Blueprint $blueprint): void {
        $blueprint->dropUnique('permissions_identity_unique');
    });

    Schema::table($table, static function (Blueprint $blueprint): void {
        $blueprint->dropColumn('identity_key');
    });

    $audit = Audit::run();

    expect($audit->unmigrated)->toBe([$table])
        ->and($audit->isClean())->toBeFalse();

    // The assertion that carries the guarantee is this one, never the count of
    // terms in `isClean()`: swapping one bucket for another leaves that count
    // exactly where it was.
    /** @var Illuminate\Testing\PendingCommand $pending */
    $pending = $this->artisan('filament-warden:audit', ['--check' => true]);

    $pending->assertExitCode(1);
});

test('a migrated catalogue reports nothing at all about its own schema', function (): void {
    expect(Audit::run()->unmigrated)->toBeEmpty();
});

test('a rule that can never be true is what makes this audit unclean, on its own', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    // The row a 2.x database carries and warden 3.0 will not write: the string
    // 'true' against a column `Post` DOES cast to bool. Warden migrates none of
    // them, so a build that only runs this command has to hear about it here.
    $row = permissionClass()::query()->withoutGlobalScopes()->where('name', 'viewAny')->orderByDesc('id')->firstOrFail();
    $row->forceFill(['options' => [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]])->save();

    // One panel that declares Post, and not `Audit::run()`, and neither half of
    // that is incidental. Without the resource the whole entity type lands in
    // `drifted`, which is red too; and `run()` walks every panel this suite
    // registers — `bare` and `lax` among them — which are red on their own
    // account, so the COMMAND's exit code cannot isolate any bucket at all here.
    // Measured: with `unsatisfiable` taken back out of the gate, an exit-code
    // assertion stayed green both times.
    //
    // What decides the exit code is `isClean()`, so that is what this asserts,
    // over a panel set where this bucket is the only thing standing in the way
    // (§6.30).
    $audit = Audit::of([Panel::make()->id('posts')->resources([PostResource::class])]);

    expect($audit->unsatisfiable)->toHaveCount(1)
        ->and($audit->unsatisfiable[0])->toContain('published')
        ->and($audit->isClean())->toBeFalse()
        ->and($audit->drifted)->toBeEmpty()
        ->and($audit->strays)->toBeEmpty()
        ->and($audit->forgotten)->toBeEmpty()
        ->and($audit->open)->toBeEmpty()
        ->and($audit->unpoliced)->toBeEmpty()
        ->and($audit->unkeyable)->toBeEmpty()
        ->and($audit->unownable)->toBeEmpty()
        ->and($audit->unmigrated)->toBeEmpty()
        ->and($audit->misconfigured)->toBeEmpty();
});

test('a role assignment whose authority is a deleted role is stranded too', function (): void {
    $outer = makeRole('outer');
    $inner = makeRole('inner');

    // Nesting made this edge possible and no foreign key reaches it: the
    // authority side of `assigned_roles` is polymorphic. Warden 3.0 sweeps both
    // pivots with `--stranded`, so a bucket that covered only `grants` would
    // call an installation clean while the command it names still had work.
    Warden::assign($inner)->to($outer);

    Context::resolve()->roleClass()::query()->whereKey($outer->getKey())->delete();

    expect(Audit::run()->stranded)->toHaveCount(1);
});

test('a loose row carrying conditions is not this bucket, because there is no model to ask', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('export');

    // A permission with no entity and conditions on it — §6.5's row, and a
    // dangerous one: with no instance, `passesConstraints()` answers the
    // polarity of the pass, so as a grant it never grants and as a prohibition
    // it always forbids. But whether a rule can EVER be true is a question about
    // a model's casts, and there is no model here to ask. Naming it in this
    // bucket would send somebody to add a cast to a class that does not exist;
    // `drifted` is where a row whose entity resolves to nothing is reported.
    $row = permissionClass()::query()->withoutGlobalScopes()->where('name', 'export')->orderByDesc('id')->firstOrFail();
    $row->forceFill(['options' => [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]])->save();

    expect(Audit::run()->unsatisfiable)->toBeEmpty();
});

test('a role assigned to another role while nesting is off is reported, and reddens nothing', function (): void {
    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::assign($inner)->to($outer);

    $audit = Audit::run();

    // Warden's own UPGRADE asks for this count before the flag is turned on,
    // because turning it on is what makes these edges live: a grant somebody
    // wrote years ago as a no-op becomes access on the next check.
    expect($audit->dormant)->toHaveCount(1)
        ->and($audit->dormant[0])->toContain('inherits')
        // Nothing to fix and nothing this package can clean, which is exactly
        // the test for a bucket that must never redden a build (§6.28). It is
        // what a SWITCH would do, not a defect.
        ->and($audit->isClean())->toBeTrue()
        ->and($audit->isSilent())->toBeFalse();
});

test('with nesting on there is nothing dormant, by definition', function (): void {
    config()->set('warden.roles.nested', true);

    $outer = makeRole('outer');
    $inner = makeRole('inner');

    Warden::assign($inner)->to($outer);

    // The edges are not dormant any more, they are the feature. Reporting them
    // would be telling somebody that what they turned on is on.
    expect(Audit::run()->dormant)->toBeEmpty();
});
