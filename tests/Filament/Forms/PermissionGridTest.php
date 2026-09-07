<?php

/**
 * The save-from-another-page test grants on `roleClass()`, not a fixture model:
 * `RoleResource` is always registered by the plugin, while the `test` panel
 * carries no resource for `Post` and nothing here adds it to
 * `filament-warden.catalog.models`. A grant on a model outside the panel's
 * catalogue draws no cell at all, which would leave the test's control
 * assertion (`stances` not empty) failing for a reason that has nothing to do
 * with what the test is about.
 *
 * A test that says "somebody else" moved a cell has to mount the field FIRST
 * and grant AFTER: `RoleGrants::plan()` opens with `if ($from === $to && !
 * $moved) continue;` — comparing the fresh store read against the payload —
 * and that guard fires BEFORE the code ever reaches the baseline comparison
 * that decides `preserved`. A grant made before the field opens leaves the
 * mount-time payload and the fresh store agreeing (both already show the
 * grant), so the guard trips and the cell is skipped outright; the baseline
 * block never runs.
 *
 * `GridHost::save()` also calls `saveRelationships()` twice — once inside
 * `getState()`, once explicitly — and the second call always finds nothing
 * left to report: the first call's own re-hydration rewrites state to match
 * the store before the second one runs, so `$from === $to` for every cell by
 * construction. That is why a save through `GridHost` always leaves the
 * container holding an all-zero `SaveReport`, whatever it actually wrote.
 */
declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Row;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\ForeignGridHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\GridHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Vault;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('the field keeps itself out of the data a record is updated with', function (): void {
    $field = PermissionGrid::make('permissions');

    expect($field->isDehydrated())->toBeFalse()
        ->and($field->isSaved())->toBeTrue();
});

test('the grid fills itself from the store, not from the record', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions.stances.'.roleClass().'.viewAny', 'granted');
});

test('a role that holds nothing opens on an empty grid', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions', [
            'stances' => [],
            'narrowing' => [],
            'until' => [],
            'baseline' => ['stances' => [], 'narrowing' => [], 'until' => []],
        ]);
});

test('saving writes what the grid says through warden', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeTrue();
});

test('saving takes away what the grid stopped saying', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'abstain']]]])
        ->call('save');

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeFalse();
});

test('the wildcard cell survives the round trip the browser actually makes', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', ['stances' => [roleClass() => [StateKey::MANAGE => 'granted']], 'narrowing' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeTrue();
});

test('a star is a segment on the wire, not a wildcard, so one cell can be addressed', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.'.StateKey::MANAGE, 'granted')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeTrue()
        ->and(Access::granted($user, 'delete', roleClass()))->toBeTrue();
});

test('a payload naming what the catalogue does not carry writes nothing and mints nothing', function (): void {
    $role = makeRole();

    $catalogued = Context::resolve()->permissionClass()::query()->withoutGlobalScopes()->count();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', [
            'stances' => [
                'App\\Models\\Nothing' => ['view' => 'granted'],
                roleClass() => ['nothingDeclaresThis' => 'granted'],
            ],
            'narrowing' => [],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Context::resolve()->grantClass()::query()->count())->toBe(0)
        ->and(Context::resolve()->permissionClass()::query()->withoutGlobalScopes()->count())->toBe($catalogued);
});

test('the field renders every tab of the catalogue at once', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertSee('data-fw-action="viewAny"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::MANAGE.'"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::DOOR.'"', escape: false);
});

test('the spare width lands on the entity column, not on a column of nothing', function (): void {
    $role = makeRole();

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    // The column that held the slack is gone from both rows of the head and
    // from every body row: with it, the action cells ended 313px short of the
    // card in a 1010px table. Measured in a browser, not asserted here — what
    // this pins is that no cell of it is drawn any more.
    expect($html)->not->toContain('fw-filler')
        ->and(mb_substr_count($html, '<tr>'))->toBeGreaterThan(0);
});

test('the folded reading draws the same cell the table does, from the same partial', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    // Two wrappers over ONE cell: both readings include the same partial with
    // the same arguments, so neither can say something about a cell that the
    // other does not. What is checked here is that the cell is drawn twice —
    // counting is the only way to see it, since the two are identical and
    // `assertSee` cannot tell them apart. That they are identical is a property
    // of the include, not of this assertion.
    // The partial puts each attribute on its own line, so what separates them is
    // whitespace and not one space.
    $drawn = preg_match_all(
        '/data-fw-row="'.preg_quote(roleClass(), '/').'"\s+data-fw-action="viewAny"/',
        $html,
    );

    expect($drawn)->toBe(2)
        ->and($html)->toContain('class="fw-stack"')
        ->and($html)->toContain('class="fw-stack-entity"');
});

test('the folded reading carries the scopes, and the wildcard sits outside them', function (): void {
    $role = makeRole();

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    expect($html)->toContain('class="fw-stack-scope" data-scope="read"')
        ->and($html)->toContain('class="fw-stack-scope" data-scope="withdraw"');

    // The wildcard is built with no scope at all, so it is drawn above the
    // groups rather than inside one — the same place the table's own column
    // gives it.
    $stack = mb_substr($html, (int) mb_strpos($html, 'class="fw-stack"'));

    // Present BEFORE ordered: a missing needle is `false`, and `(int) false` is
    // position zero — which reads as "first" and passes. Deleting the wildcard
    // from the folded reading left this green.
    expect($stack)->toContain('data-fw-action="'.StateKey::MANAGE.'"');

    $manage = mb_strpos($stack, 'data-fw-action="'.StateKey::MANAGE.'"');
    $firstScope = mb_strpos($stack, 'class="fw-stack-scope"');

    expect($firstScope)->not->toBeFalse()
        ->and((int) $manage)->toBeLessThan((int) $firstScope);
});

test('the grid hung on a record that is not a role asks it nothing about protection', function (): void {
    $post = Post::query()->create(['title' => 'A post']);
    $stranger = makeUser('super-admin');

    livewire(ForeignGridHost::class, ['recordClass' => Post::class, 'recordKey' => $post->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);

    livewire(ForeignGridHost::class, ['recordClass' => User::class, 'recordKey' => $stranger->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);
});

test('a cell the policy does not declare renders as a dot and not as a control', function (): void {
    config()->set('filament-warden.catalog.models', [ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag::class]);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-void', escape: false);
});

test('a narrowed cell is marked, and its rule travels to the browser', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', roleClass())->where('id', 1);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions.narrowing.'.roleClass().'.update.mode', 'conditions')
        ->assertSee('data-noted="true"', escape: false);
});

test('a rule this screen cannot draw is marked, locked, and kept out of the state', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', roleClass())->where('id', 1);
    Warden::allow($role)->to('update', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions.narrowing', [])
        ->assertSee('data-locked="true"', escape: false);
});

test('what the field offers is what the catalogue holds', function (): void {
    $role = makeRole();

    $grid = PermissionGrid::make('permissions');

    expect(RoleGrants::of($role, Catalog::for(Filament::getPanel('test')))->stances)->toBeEmpty()
        ->and($grid->getName())->toBe('permissions');
});

test('the browser is handed the component, the state and the rules, in that order', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-load-src', escape: false)
        ->assertSee('permission-grid', escape: false)
        ->assertSee('wardenPermissionGrid({', escape: false)
        ->assertSee('$wire.$entangle(', escape: false);
});

test('the cycle order travels to the browser instead of being written there', function (): void {
    $role = makeRole();

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    $matches = [];
    preg_match("/JSON\.parse\('(.+?)'\)/", $html, $matches);
    $encoded = $matches[1] ?? '';

    $unescaped = json_decode('"'.$encoded.'"');

    /** @var array{order: list<string>, manage: string} $payload */
    $payload = json_decode(is_string($unescaped) ? $unescaped : '{}', true, 512, JSON_THROW_ON_ERROR);

    expect($payload['order'])->toBe(['abstain', 'granted', 'forbidden'])
        ->and($payload['manage'])->toBe('*');
});

test('the script carries no stance of its own, so php stays the only authority', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    foreach (['abstain', 'granted', 'forbidden'] as $stance) {
        expect(str_contains($script, "'{$stance}'"))->toBeFalse("[{$stance}] is written into the script");
    }
});

test('shift reaches the denial from the keyboard too, which the mouse alone did not', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-on:keydown.enter.prevent', escape: false)
        ->assertSee('$event.shiftKey', escape: false);
});

test('the state binding stays deferred, so a click costs no round trip', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('$entangle(&#039;data.permissions&#039;, false)', escape: false);
});

test('the browser can ask why one cell is the way it is', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted'
            && $why['cause'] === 'granted-directly'
            && is_string($why['summary'])
            && $why['pending'] === null);
});

test('a forbidden cell says forbidden, not merely not allowed', function (): void {
    $role = makeRole();

    Warden::forbid($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'forbidden'
            && $why['cause'] === 'forbidden-directly');
});

test('a cell nothing matches says warden abstains, which is another answer', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'create'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'abstain'
            && $why['cause'] === 'no-matching-grant'
            && $why['permission'] === null);
});

test('a stance changed on screen is called out, because the answer is about the store', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'forbidden']]]])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted' && is_string($why['pending']));
});

test('the wildcard column is explained too, though no policy declares it', function (): void {
    $role = makeRole();

    Warden::allow($role)->toManage(roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), StateKey::MANAGE])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted');
});

test('a policy action named manage is explained as itself, not as the wildcard', function (): void {
    config()->set('filament-warden.catalog.models', [Vault::class]);

    $role = makeRole();

    Warden::allow($role)->to('manage', Vault::class);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [Vault::class, 'manage'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted'
            && $why['cause'] === 'granted-directly');
});

test('the wildcard cell of that row is explained by the star, which nothing granted', function (): void {
    config()->set('filament-warden.catalog.models', [Vault::class]);

    $role = makeRole();

    Warden::allow($role)->to('manage', Vault::class);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [Vault::class, StateKey::MANAGE])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'abstain'
            && $why['permission'] === null);
});

test('a door is explained by its own name', function (): void {
    $role = makeRole();
    $door = 'panel:test';

    Warden::allow($role)->to($door);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [$door, 'access'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted');
});

test('a cell that is not on the grid is not explained', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', ['App\Models\Nothing', 'viewAny'])
        ->assertReturned([]);
});

test('a role being created explains its cells instead of answering nothing', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'abstain'
            && $why['cause'] === null
            && is_string($why['summary'])
            && $why['summary'] !== ''
            && array_keys($why) === ['verdict', 'cause', 'summary', 'permission', 'role', 'narrowed', 'pending', 'until']);
});

test('the inspector is on the screen, waiting to be asked', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-inspector', escape: false)
        ->assertSee(__('filament-warden::ui.explain.empty'))
        ->assertSee('x-on:click="pick(', escape: false);
});

test('the inspector carries a sentence for an answer that never came', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-show="selected && failed && ! loading"', escape: false)
        ->assertSee('The answer never arrived');
});

test('the panel names its two voices, and the raw cause is not one of them', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // The panel used to say "No grant matches" and, three lines down,
    // "granted". They do not disagree: one speaks for the store and the other
    // for the screen, with an unsaved change between them. Nothing said so.
    expect($html)->toContain(__('filament-warden::ui.explain.stored'))
        ->and($html)->toContain(__('filament-warden::ui.explain.screen'))
        ->and($html)->toContain(__('filament-warden::ui.explain.matched'))
        ->and($html)->toContain('class="fw-voices"');

    // The cause code was jargon in a monospaced chip, and the sentence beside
    // it already said what it said.
    expect($html)->not->toContain('fw-why-cause');

    // The panel is a region and used to have no name. The key that titled it
    // when nothing was selected names it now. Built from the translation and
    // not the literal word: the suite sets no locale.
    $title = preg_quote(__('filament-warden::ui.explain.title'), '/');

    expect($html)->toMatch('/<aside[^>]+class="fw-inspector"[^>]+aria-label="'.$title.'"/');
});

test('the script asks the server for the answer and composes none of it', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain("callSchemaComponentMethod(this.grid.key, 'explainCell'")
        ->and($script)->not->toContain('Granted by');
});

test('the script refuses to call a boolean a match without asking the columns', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain(
        "booleanMisfit(rule) {\n".
        "        return rule.kind === 'value'\n".
        "            && (rule.value === 'true' || rule.value === 'false')\n".
        "            && ! this.source.booleans.includes(rule.column)\n".
        '    },',
    );
});

test('an answer that arrives late is not painted onto the cell that replaced it', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain('const token = (this.asked ?? 0) + 1')
        ->and($script)->toContain('if (this.asked !== token) {')
        ->and(mb_substr_count($script, 'if (this.asked === token) {'))->toBe(2)
        ->and($script)->toContain('} catch {')
        ->and($script)->toContain('this.failed = true')
        ->and($script)->not->toContain('this.selected !== asked')
        ->and($script)->not->toContain('this.selected === asked');
});

test('a grid with its inspector closed asks the server nothing at all', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain('if (! this.grid.explain && ! this.grid.constraints) {')
        ->and($script)->toContain("if (! this.grid.explain && ! this.grid.constraints) {\n                return\n            }");
});

test('the browser is told what a condition on this cell could be built from', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => $narrowing['model'] === roleClass()
            && in_array('name', stringsOf($narrowing, 'columns'), true)
            && in_array('email', stringsOf($narrowing, 'authority'), true)
            && partOf($narrowing, 'stored')['mode'] === 'all');
});

test('the inspector says which of the two refusals it is, never a column for neither', function (): void {
    config()->set('warden.ownership.default_attribute');
    app()->forgetInstance(Context::class);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'ownership')['available'] === false
            && partOf($narrowing, 'ownership')['reason'] === __('filament-warden::ui.conditions.no_ownership_resolver'));
});

test('a cell whose model does resolve ownership carries no refusal at all', function (): void {
    Warden::ownedVia(roleClass(), static fn (): bool => true);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'ownership')['available'] === true
            && partOf($narrowing, 'ownership')['reason'] === null);
});

test('the cell inspector gets the same boolean list a condition may compare, never undefined', function (): void {
    $role = makeRole();
    $door = 'page:'.Reports::class;

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => $narrowing['booleans'] === Columns::booleans(roleClass()));

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [$door, StateKey::DOOR])
        ->assertReturned(fn (array $narrowing): bool => array_key_exists('booleans', $narrowing)
            && $narrowing['booleans'] === []);
});

test('a door has no model, so it is told why it can hold no condition', function (): void {
    $role = makeRole();
    $door = 'page:'.Reports::class;

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [$door, StateKey::DOOR])
        ->assertReturned(fn (array $narrowing): bool => $narrowing['model'] === null
            && $narrowing['stored'] === null
            && is_string(partOf($narrowing, 'ownership')['reason']));
});

test('ownership is refused with the column that is missing named', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(function (array $narrowing): bool {
            $ownership = partOf($narrowing, 'ownership');
            $reason = $ownership['reason'] ?? null;

            return $ownership['available'] === false && is_string($reason) && str_contains($reason, 'user_id');
        });
});

test('a stored condition comes back written out, so the screen can read it aloud', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'stored')['mode'] === 'conditions'
            && partOf($narrowing, 'stored')['preview'] === 'name = editor'
            && partOf($narrowing, 'stored')['locked'] === false
            && partOf($narrowing, 'stored')['note'] === null);
});

test('a rule this screen cannot draw comes back locked, with the reason written', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');
    Warden::allow($role)->to('update', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'stored')['locked'] === true
            && is_string(partOf($narrowing, 'stored')['note']));
});

test('a locked cell hands the browser the reach the store holds, not every row', function (): void {
    $role = makeRole();

    Warden::allow($role)->toOwn(roleClass(), 'update')->where('name', '=', 'editor');

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'stored')['locked'] === true
            && partOf($narrowing, 'stored')['mode'] === 'unreadable'
            && partOf($narrowing, 'stored')['preview'] === 'name = editor');
});

test('the buttons follow the store on a cell nobody may change', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-bind:aria-checked="reachOf() === mode', escape: false)
        ->assertDontSee('x-bind:aria-checked="modeOf() === mode', escape: false);
});

test('the reach picker is one tab stop, and the arrows walk it', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('role="radiogroup"', escape: false)
        ->assertSee('role="radio"', escape: false)
        ->assertSee('x-bind:tabindex="reachStop() === mode ? 0 : -1"', escape: false)
        ->assertSee('stepReach($el, 1)', escape: false)
        ->assertSee('stepReach($el, -1)', escape: false);
});

test('what may be picked is answered once, and the arrows read the same answer', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');
    $role = makeRole();

    // The predicate lives in the script and the markup binds to it, rather than
    // each of the two spelling it out: the arrows have to skip exactly what the
    // buttons disable, and two copies of that rule inside one component is the
    // half `make coverage` cannot see.
    expect($script)->toContain("&& (mode !== 'owned' || this.narrowing.ownership.available)");

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-bind:disabled="! reachEnabled(mode)"', escape: false)
        // The shape the predicate takes when it is spelled out in the markup
        // instead of asked for, which is what it looked like before.
        ->assertDontSee("mode === 'owned' && ! narrowing.ownership.available", escape: false);
});

test('why a reach cannot be picked is said outside the option, which cannot be focused', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('class="fw-reach-reason"', escape: false)
        ->assertSee('x-text="narrowing.ownership.reason"', escape: false);
});

test('a locked cell says why in the hint slot, and says it once', function (): void {
    $role = makeRole();

    // `reachOf()` answers with the stored `Shape` when locked, and that enum has
    // six cases to `grid.modes`' three — so the slot has to fall through to the
    // stored note rather than index a map that has no entry for it.
    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('grid.modes[reachOf()] ? grid.modes[reachOf()].hint : narrowing.stored.note', escape: false)
        ->assertDontSee('x-show="narrowing.stored.locked" x-text="narrowing.stored.note"', escape: false);
});

test('which reach lights branches on whether the store may be changed', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain('return this.narrowing.stored.locked ? this.narrowing.stored.mode : this.modeOf()');
});

test('a locked cell draws the rule the store holds, read only', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-text="narrowing.stored.preview"', escape: false);
});

test('saving carries the condition the screen drew all the way to the store', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => [
            'stances' => [roleClass() => ['update' => 'granted']],
            'narrowing' => [roleClass() => ['update' => [
                'mode' => 'conditions',
                'rules' => [['logic' => 'and', 'kind' => 'value', 'column' => 'name', 'operator' => '=', 'value' => 'editor']],
            ]]],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    // Nullable in the signature and never null in fact: it throws when there is
    // no panel at all.
    /** @var Panel $panel */
    $panel = Filament::getCurrentOrDefaultPanel();

    $narrowing = RoleGrants::of($role, Catalog::for($panel))->narrowings[roleClass()]['update'];

    expect($narrowing->rules[0]->column)->toBe('name')
        ->and($narrowing->rules[0]->value)->toBe('editor')
        ->and(Access::granted($user, 'update', roleClass()))->toBeFalse();
});

test('an installation that closed the inspector is not asked why', function (): void {
    config()->set('filament-warden.grid.explain', false);

    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why === []);
});

test('an installation that closed the builder is not asked what to build with', function (): void {
    config()->set('filament-warden.grid.constraints', false);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'form.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => $narrowing === []);
});

test('a grid with both closed draws no inspector at all', function (): void {
    config()->set('filament-warden.grid.explain', false);
    config()->set('filament-warden.grid.constraints', false);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertDontSee('fw-inspector', escape: false);
});

test('closing the builder keeps a narrowed rule instead of widening it', function (): void {
    config()->set('filament-warden.grid.constraints', false);

    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [
            roleClass() => ['update' => 'granted', 'viewAny' => 'granted'],
        ]]])
        ->call('save')
        ->assertHasNoFormErrors();

    // Nullable in the signature and never null in fact.
    /** @var Panel $panel */
    $panel = Filament::getCurrentOrDefaultPanel();

    $narrowing = RoleGrants::of($role, Catalog::for($panel))->narrowings[roleClass()]['update'];

    expect($narrowing->shape)->toBe(ElPandaPe\FilamentWarden\Conditions\Shape::Conditions)
        ->and($narrowing->rules[0]->value)->toBe('editor');
});

test('the tally of a role that holds everything lights up, instead of reading zero', function (): void {
    $role = makeRole('super-admin');

    Warden::allow($role)->everything();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-wider', escape: false)
        ->assertSee('data-on="true"', escape: false)
        ->assertDontSee('>0</span>', escape: false);
});

test('a rule pinned to one record is said above the grid', function (): void {
    $role = makeRole();
    $other = makeRole('reviewer');

    Warden::allow($role)->to('view', $other);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-records', escape: false)
        ->assertSee('#'.recordKey($other))
        ->assertSee('This role also holds rules pinned to single records.');
});

test('a granted and a forbidden record-pinned rule read apart in words', function (): void {
    $granted = makeRole();
    $forbidden = makeRole('auditor');
    $target = makeRole('reviewer');

    Warden::allow($granted)->to('view', $target);
    Warden::forbid($forbidden)->to('view', $target);

    $grantedHtml = livewire(GridHost::class, ['roleKey' => $granted->getKey()])->html();
    $forbiddenHtml = livewire(GridHost::class, ['roleKey' => $forbidden->getKey()])->html();

    expect($grantedHtml)
        ->toContain('>'.__('filament-warden::ui.grid.states.granted').'</span>')
        ->and($forbiddenHtml)
        ->toContain('>'.__('filament-warden::ui.grid.states.forbidden').'</span>');
});

/**
 * The wider-rule notice's own markup, cut out of the rendered page by its
 * class. A cell reached by the same wider rule says the identical word in its
 * own sr-only span (§ box.blade.php), so an unscoped assertion would pass
 * whether or not this block carried the word itself — this is what forces the
 * assertion to look only inside `<p class="fw-wider">`.
 */
function widerNoticeOf(string $html): string
{
    $pattern = '/<p class="fw-wider">.*?<\/p>/s';

    $matches = [];

    return preg_match($pattern, $html, $matches) === 1 ? $matches[0] : '';
}

test('the wider rule a role holds over everything reads apart in words', function (): void {
    $granted = makeRole();
    $forbidden = makeRole('auditor');

    Warden::allow($granted)->everything();
    Warden::forbid($forbidden)->everything();

    $grantedHtml = livewire(GridHost::class, ['roleKey' => $granted->getKey()])->html();
    $forbiddenHtml = livewire(GridHost::class, ['roleKey' => $forbidden->getKey()])->html();

    expect(widerNoticeOf($grantedHtml))
        ->toContain('>'.__('filament-warden::ui.grid.states.granted').'</span>')
        ->and(widerNoticeOf($forbiddenHtml))
        ->toContain('>'.__('filament-warden::ui.grid.states.forbidden').'</span>');
});

test('a grid the application disabled says so, and does not call the role protected', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey(), 'locked' => true])
        ->assertSee('fw-read-only-notice', escape: false)
        ->assertSee('its cells select, they do not cycle', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);
});

test('a grid nobody disabled says nothing at all', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertDontSee('fw-read-only-notice', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);
});

/**
 * One cell's markup, picked out of the rendered grid by the two attributes that
 * identify it. The accessible name of a button is computed from what is INSIDE
 * it, so an assertion about that name has to look inside one button and never at
 * the page: the same word is on the legend, on six other cells and in a tooltip.
 */
function boxOf(string $html, string $row, string $action): string
{
    $pattern = '/<button[^>]*data-fw-row="'.preg_quote($row, '/')
        .'"[^>]*data-fw-action="'.preg_quote($action, '/').'".*?<\/button>/s';

    $matches = [];

    return preg_match($pattern, $html, $matches) === 1 ? $matches[0] : '';
}

test('a cell says its state out loud, not only in a data attribute', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());
    Warden::forbid($role)->to('delete', roleClass());

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    expect(boxOf($html, roleClass(), 'viewAny'))
        ->toContain('>'.__('filament-warden::ui.grid.states.granted').'</span>')
        ->and(boxOf($html, roleClass(), 'delete'))
        ->toContain('>'.__('filament-warden::ui.grid.states.forbidden').'</span>')
        ->and(boxOf($html, roleClass(), 'create'))
        ->toContain('>'.__('filament-warden::ui.grid.states.abstain').'</span>');
});

test('a cell nobody wrote says the wider rule that answers for it', function (): void {
    $role = makeRole();

    Warden::allow($role)->toManage(roleClass());

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    expect(boxOf($html, roleClass(), 'viewAny'))
        ->toContain('>'.__('filament-warden::ui.grid.states.granted').'</span>')
        ->toContain('>'.__('filament-warden::ui.grid.states.broader').'</span>')
        ->and(boxOf($html, roleClass(), StateKey::MANAGE))
        ->toContain('>'.__('filament-warden::ui.grid.states.granted').'</span>')
        ->not->toContain('>'.__('filament-warden::ui.grid.states.broader').'</span>');
});

test('a narrowed cell and a locked one each say which', function (): void {
    $narrowed = makeRole('narrowed');

    Warden::allow($narrowed)->to('update', roleClass())->where('name', 'editor');

    $tangled = makeRole('tangled');

    Warden::allow($tangled)->to('update', roleClass())->where('name', 'editor');
    Warden::allow($tangled)->to('update', roleClass());

    $one = livewire(GridHost::class, ['roleKey' => $narrowed->getKey()])->html();
    $two = livewire(GridHost::class, ['roleKey' => $tangled->getKey()])->html();

    expect(boxOf($one, roleClass(), 'update'))
        ->toContain('>'.__('filament-warden::ui.grid.states.narrowed').'</span>')
        ->not->toContain('>'.__('filament-warden::ui.grid.states.locked').'</span>')
        ->and(boxOf($two, roleClass(), 'update'))
        ->toContain('>'.__('filament-warden::ui.grid.states.locked').'</span>')
        ->not->toContain('>'.__('filament-warden::ui.grid.states.narrowed').'</span>');
});

test('an undeclared cell says so instead of reading as a dot', function (): void {
    config()->set('filament-warden.catalog.models', [ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag::class]);

    $role = makeRole();

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    expect($html)->toContain(
        '<span aria-hidden="true">·</span><span class="fw-sr">'
        .__('filament-warden::ui.grid.states.undeclared').'</span>',
    );
});

test('every tab names the panel it opens, and only the open one is a tab stop', function (): void {
    $role = makeRole();

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    $tabs = [];
    preg_match_all('/<button[^>]*role="tab".*?<\/button>/s', $html, $tabs);

    $stops = array_filter($tabs[0], static fn (string $tab): bool => str_contains($tab, 'tabindex="0"'));

    expect(count($tabs[0]))->toBeGreaterThan(1)
        ->and($stops)->toHaveCount(1)
        ->and($tabs[0][0])
        ->toContain('id="fw-form-permissions-tab-resources"')
        ->toContain('aria-controls="fw-form-permissions-panel-resources"')
        ->toContain('data-fw-tab="resources"')
        ->and($html)
        ->toContain('id="fw-form-permissions-panel-resources"')
        ->toContain('aria-labelledby="fw-form-permissions-tab-resources"')
        ->toContain('x-on:keydown.arrow-right.prevent="stepTab($el, 1)"')
        ->toContain('x-on:keydown.arrow-left.prevent="stepTab($el, -1)"')
        ->toContain('x-on:keydown.home.prevent="edgeTab($el, false)"')
        ->toContain('x-on:keydown.end.prevent="edgeTab($el, true)"');
});

test('the inspector has a place to speak from before it has anything to say', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('<p class="fw-sr" role="status" x-text="failed ?', escape: false);
});

test('a save under one panel leaves alone what only another panel declares', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    Warden::allow($role)->to('view', Post::class);
    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', ['stances' => [], 'narrowing' => []])
        ->call('save');

    expect(Access::granted($user, 'view', Post::class))->toBeTrue()
        ->and(Access::granted($user, 'viewAny', roleClass()))->toBeFalse();
});

test('and under the panel that does declare it, the very same save takes it away', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    Warden::allow($role)->to('view', Post::class);

    $panel = Panel::make()->id('other')->resources([PostResource::class]);
    $panel->boot();
    Filament::setCurrentPanel($panel);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', ['stances' => [], 'narrowing' => []])
        ->call('save');

    expect(Access::granted($user, 'view', Post::class))->toBeFalse();
});

test('a grid on somebody else page starts its next save from what is actually stored', function (): void {
    $role = makeRole('editor');

    $component = livewire(GridHost::class, ['roleKey' => $role->getKey()]);

    Warden::allow($role)->to('viewAny', roleClass());

    $component->call('save');

    /**
     * @var array{
     *     stances: array<string, array<string, string>>,
     *     narrowing: array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>,
     *     baseline: array{
     *         stances: array<string, array<string, string>>,
     *         narrowing: array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>,
     *     },
     * } $state
     */
    $state = $component->get('data.permissions');

    expect($state['baseline']['stances'])->toBe($state['stances'])
        ->and($state['baseline']['narrowing'])->toBe($state['narrowing'])
        ->and($state['stances'])->not->toBeEmpty();
});

test('the field is what says the save met somebody else', function (): void {
    $role = makeRole('editor');

    $component = livewire(GridHost::class, ['roleKey' => $role->getKey()]);

    Warden::allow($role)->to('viewAny', roleClass());

    $component->call('save')
        ->assertNotified(__('filament-warden::ui.grid.concurrent.kept_title'));
});

test('a save that met nobody says nothing extra', function (): void {
    $role = makeRole('editor');

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('save')
        ->assertNotNotified(__('filament-warden::ui.grid.concurrent.kept_title'));
});

test('nothing is announced when the save is rolled back', function (): void {
    $role = makeRole('editor');

    $component = livewire(GridHost::class, ['roleKey' => $role->getKey()]);

    Warden::allow($role)->to('viewAny', roleClass());

    $connection = DB::connection(Context::resolve()->connection());

    expect(fn () => $connection->transaction(function () use ($component): void {
        $component->call('save');

        throw new RuntimeException('a failure the field cannot see coming');
    }))->toThrow(RuntimeException::class);

    $component->assertNotNotified(__('filament-warden::ui.grid.concurrent.kept_title'));
});

test('what the save did stays reachable in the container for a page that wants to say more', function (): void {
    $role = makeRole('editor');

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->call('save');

    expect(app()->bound(SaveReport::class))->toBeTrue();

    $report = app(SaveReport::class);

    expect($report->written)->toBe(0)
        ->and($report->preserved)->toBe(0)
        ->and($report->refused)->toBeEmpty();
});

test('one filter answers for both readings, and it never reaches the state', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();

    // One input, above both readings, so the table and the fold cannot disagree
    // about which rows exist.
    expect(mb_substr_count($html, 'class="fw-filter-field"'))->toBe(1);

    $table = mb_substr($html, (int) mb_strpos($html, 'class="fw-scroll"'), (int) mb_strpos($html, 'class="fw-stack"') - (int) mb_strpos($html, 'class="fw-scroll"'));
    $stack = mb_substr($html, (int) mb_strpos($html, 'class="fw-stack"'));

    expect($table)->toContain('x-show="shown(')
        ->and($stack)->toContain('x-show="shown(');

    $filter = mb_strpos($html, 'class="fw-filter"');
    $scroll = mb_strpos($html, 'class="fw-scroll"');
    $stack = mb_strpos($html, 'class="fw-stack"');

    expect($filter)->toBeLessThan((int) $scroll)
        ->and($filter)->toBeLessThan((int) $stack);

    // What the browser filters ON. Without these two the predicate would have to
    // read the DOM back, which is the shape that ends up rebuilding state.
    $matches = [];
    preg_match("/JSON\.parse\('(.+?)'\)/", $html, $matches);
    $unescaped = json_decode('"'.($matches[1] ?? '').'"');

    /** @var array{rows: array<string, array{label: string, model: string|null}>} $payload */
    $payload = json_decode(is_string($unescaped) ? $unescaped : '{}', true, 512, JSON_THROW_ON_ERROR);

    expect($payload['rows'][roleClass()]['label'])->not->toBeEmpty()
        ->and($payload['rows'][roleClass()]['model'])->toBe(roleClass());
});

test('the filter says out loud what it took away', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // The window used to be 900 characters wide and asked only whether
    // `role="status"` appeared SOMEWHERE inside it. The grid has other live
    // regions — the inspector has had one since `1.1.0` — so a second one
    // landing anywhere near the filter would have kept this green with the
    // filter's own gone. It asks for the filter's own node now.
    $filter = mb_substr($html, (int) mb_strpos($html, 'class="fw-filter"'), 900);

    expect($filter)->toContain('role="status"')
        ->and($filter)->toContain('x-text="filter.trim() === \'\' ? \'\' : filterCount(')
        ->and($filter)->toContain('class="fw-sr"');
});

test('the folded reading carries the presets the table row has, and its own count', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', Post::class);

    $html = livewire(GridHost::class, ['roleKey' => $role->getKey()])->html();
    $stack = mb_substr($html, (int) mb_strpos($html, 'class="fw-stack"'));

    // In the body of the disclosure, never in its `<summary>`, where a click
    // would toggle the fold instead of applying the preset.
    $shortcuts = mb_strpos($stack, 'fw-stack-shortcuts');
    $rows = mb_strpos($stack, 'class="fw-stack-rows"');

    expect($stack)->toContain('fw-stack-shortcuts')
        ->and($rows)->toBeLessThan((int) $shortcuts)
        ->and($stack)->toContain('class="fw-stack-summary"');
});

test('the fold count and the row it counts agree, server and browser', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());
    Warden::forbid($role)->to('delete', roleClass());

    $catalog = Catalog::for(Filament::getPanel('test'));
    $stored = RoleGrants::of($role, $catalog);

    // The third argument is what is ON SCREEN, and the cells answer from that:
    // a view built without it draws a role that has nothing.
    $view = GridView::for($catalog, $stored, $stored->stances);

    /** @var Row $row */
    $row = collect($view->tabs[0]->rows)->first(static fn (Row $candidate): bool => $candidate->model === roleClass());

    $answered = $row->answered();

    expect($answered['granted'])->toBe(1)
        ->and($answered['forbidden'])->toBe(1)
        ->and($view->summaryOf($row))->toBe('1 of '.$answered['total'].' · 1 forbidden');
});

test('the grid carries one live region for what a click just did, from the first paint', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // One for the whole grid, not one per tab: only one cell can be clicked at
    // a time. And empty, and never behind a condition — a live region that
    // arrives with its text already in it is one NVDA and JAWS do not announce.
    expect(mb_substr_count($html, 'x-text="said"'))->toBe(1)
        ->and($html)->toContain('<p class="fw-sr" role="status" x-text="said"></p>');
});

test('a click still announces with the inspector and the builder both switched off', function (): void {
    config()->set('filament-warden.grid.explain', false);
    config()->set('filament-warden.grid.constraints', false);

    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // The configuration that used to be silent: `select()` returns before doing
    // anything when both are off, so an announcement hung off it would never
    // fire. This one hangs off `write()`.
    expect($html)->toContain('x-text="said"');

    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    $write = mb_substr($script, (int) mb_strpos($script, 'write(row, action, stance) {'));

    expect(mb_substr($write, 0, (int) mb_strpos($write, "\n        },")))->toContain('this.said = this.spoken(');
});

test('a row is named by its entity, not by the buttons that sit beside it', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // The presets live inside the row header, so a header named by its contents
    // is announced with the three button labels glued on: "Roles … read all
    // none", on every row. Seen in an accessibility tree dump, not guessed.
    expect($html)->toContain('aria-labelledby="')
        ->and($html)->toMatch('/<th\s+class="fw-entity"\s+scope="row"\s+aria-labelledby="[^"]+-name [^"]+-model"/');

    // The spare-width column is gone: nothing in the head is a column header
    // without a name any more.
    expect($html)->not->toContain('fw-filler');
});

test('why an option cannot be picked is pointed at by the option it is about', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // A radiogroup puts a reader in focus mode, where the arrows read each
    // radio's name and its description and nothing else — so a paragraph sitting
    // outside with nothing pointing at it was never reached, which is what an
    // accessibility tree dump showed. Only the option it is about points at it,
    // and only while there is a reason: describing "every row" with why "only
    // what it owns" cannot be picked would be worse than saying nothing.
    expect($html)->toContain('-reach-reason"')
        ->and($html)->toMatch('/x-bind:aria-describedby="[^"]*reach-reason/s')
        ->and($html)->toMatch("/mode === 'owned' &&\s*narrowing\.ownership\.reason/");
});

test('the key is folded away above the grid, in two named groups', function (): void {
    $html = livewire(GridHost::class, ['roleKey' => makeRole()->getKey()])->html();

    // Above the tabs and not between a cell and its answer: the legend used to
    // sit under the grid, so the panel that says why a cell is the way it is
    // was eight lines of vocabulary further down.
    $key = mb_strpos($html, 'fw-legend-fold');
    $tabs = mb_strpos($html, 'class="fw-tabs"');

    // Cast for PHPStan, not for the assertion: `toBeInt()` proves the value at
    // runtime but does not narrow `int<0, max>|false` for `toBeLessThan()`,
    // which wants an `int` argument — the same shape StylesheetTest already
    // works around for `mb_strpos()`.
    expect($key)->toBeInt()
        ->and($tabs)->toBeInt()
        ->and((int) $key)->toBeLessThan((int) $tabs);

    // Two groups, because the vocabulary has two halves: the three a person
    // sets with a click, and the four the grid adds on its own.
    expect($html)->toContain(__('filament-warden::ui.grid.legend.title'))
        ->and($html)->toContain(__('filament-warden::ui.grid.legend.set'))
        ->and($html)->toContain(__('filament-warden::ui.grid.legend.added'))
        ->and(mb_substr_count($html, 'class="fw-key-group"'))->toBe(2);
});

test('a grid with expiry switched off keeps dates a screen never sent', function (): void {
    $role = makeRole();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->until(Carbon::parse('2026-09-14 12:00:00'))->to('viewAny', roleClass());

    // A state with no `until` key at all — which is what a page building its own
    // state produces, and what this field's own envelope did before 3.0.0.
    // `State::untils()` reads that as the empty map, and the empty map means
    // CLEARED. Only the config tells "this screen has no opinion" from "this
    // screen removed them", and getting it wrong ends every timed grant on the
    // grid on the first save from such a page.
    $without = ['stances' => [roleClass() => ['viewAny' => 'granted']], 'narrowing' => []];

    config()->set('filament-warden.grid.expiry', false);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', $without)
        ->call('save');

    $kept = RoleGrants::of($role, Catalog::for(Filament::getPanel('test')))->untils;

    expect($kept[roleClass()]['viewAny']->toIso8601String())->toStartWith('2026-09-14T12:00:00');

    // And with it on, the same state is an instruction: the date goes.
    config()->set('filament-warden.grid.expiry', true);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->set('data.permissions', $without)
        ->call('save');

    expect(RoleGrants::of($role, Catalog::for(Filament::getPanel('test')))->untils)->toBeEmpty();

    Carbon::setTestNow();
});
