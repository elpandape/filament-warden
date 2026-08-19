<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\GridHost;
use ElPandaPe\FilamentWarden\Tests\TestCase;
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

test('the field renders every tab of the catalogue at once', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertSee('data-fw-action="viewAny"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::MANAGE.'"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::DOOR.'"', escape: false);
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
        ->and($payload['manage'])->toBe('manage');
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
        ->call('callSchemaComponentMethod', 'form.permissions', 'explainCell', [roleClass(), 'manage'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'granted');
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

test('the inspector is on the screen, waiting to be asked', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-inspector', escape: false)
        ->assertSee('Click a cell of the grid')
        ->assertSee('x-on:click="pick(', escape: false);
});

test('the script asks the server for the answer and composes none of it', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/permission-grid.js');

    expect($script)->toContain("callSchemaComponentMethod(this.grid.key, 'explainCell'")
        ->and($script)->not->toContain('Granted by');
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
