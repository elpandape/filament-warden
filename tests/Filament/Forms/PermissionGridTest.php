<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
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
        ->assertSet('data.permissions', ['stances' => [], 'narrowing' => []]);
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
            && array_keys($why) === ['verdict', 'cause', 'summary', 'permission', 'role', 'narrowed', 'pending']);
});

test('the inspector is on the screen, waiting to be asked', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-inspector', escape: false)
        ->assertSee('Click a cell of the grid')
        ->assertSee('x-on:click="pick(', escape: false);
});

test('the inspector carries a sentence for an answer that never came', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-show="selected && failed && ! loading"', escape: false)
        ->assertSee('The answer never arrived');
});

test('a verdict with no cause behind it shows no empty slot where one would go', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('x-show="why.cause"', escape: false);
});

test('the script asks the server for the answer and composes none of it', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain("callSchemaComponentMethod(this.grid.key, 'explainCell'")
        ->and($script)->not->toContain('Granted by');
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
        ->assertSee('x-bind:data-on="reachOf() === mode', escape: false)
        ->assertDontSee('x-bind:data-on="modeOf() === mode', escape: false);
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
