<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Cell;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Row;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Tab;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Panel;

pest()->extend(TestCase::class);

/**
 * @param  array<string, array<string, string>>  $state
 * @param  array<string, array<string, Narrowing>>  $narrowings
 * @param  array<string, string>  $wider
 */
function gridFor(Panel $panel, array $state = [], array $narrowings = [], array $wider = []): GridView
{
    return GridView::for(Catalog::for($panel), new RoleState([], $narrowings, $wider), $state);
}

function tabNamed(GridView $grid, string $key): Tab
{
    foreach ($grid->tabs as $tab) {
        if ($tab->key === $key) {
            return $tab;
        }
    }

    throw new RuntimeException("No tab [{$key}].");
}

function rowFor(Tab $tab, string $key): Row
{
    foreach ($tab->rows as $row) {
        if ($row->key === $key) {
            return $row;
        }
    }

    throw new RuntimeException("No row [{$key}].");
}

function cellFor(Row $row, string $action): Cell
{
    foreach ($row->allCells() as $cell) {
        if ($cell->action === $action) {
            return $cell;
        }
    }

    throw new RuntimeException("No cell [{$action}].");
}

test('the columns are grouped by scope, in the order the scopes escalate', function (): void {
    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(array_map(fn (ElPandaPe\FilamentWarden\Filament\Forms\Grid\ColumnGroup $group): string => $group->scope->value, $grid->groups))
        ->toBe(['read', 'write', 'withdraw']);
});

test('a column exists for every action any policy declares, and none for the rest', function (): void {
    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    $actions = [];

    foreach ($grid->groups as $group) {
        foreach ($group->columns as $column) {
            $actions[] = $column->action;
        }
    }

    expect($actions)->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('every entity row offers the wildcard, which is the column warden stores as a star', function (): void {
    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    $row = rowFor(tabNamed($grid, 'resources'), Post::class);

    expect($row->manage)->not->toBeNull()
        ->and(cellFor($row, StateKey::MANAGE)->action)->toBe(StateKey::MANAGE);
});

test('an action the policy does not declare is a dot, not a control', function (): void {
    config()->set('filament-warden.catalog.models', [Tag::class]);

    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    $tag = rowFor(tabNamed($grid, 'resources'), Tag::class);

    expect(cellFor($tag, 'viewAny')->declared)->toBeTrue()
        ->and(cellFor($tag, 'create')->declared)->toBeFalse()
        ->and(cellFor($tag, 'create')->isEditable())->toBeFalse()
        ->and(cellFor($tag, 'delete')->declared)->toBeFalse();
});

test('a row offers the browser only the actions its own policy declares', function (): void {
    config()->set('filament-warden.catalog.models', [Tag::class]);

    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect($grid->alpine()['rows'][Tag::class]['actions'])->toBe(['viewAny', 'view']);
});

test('what the role wrote is what the cell shows', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => ['viewAny' => 'granted', 'delete' => 'forbidden']],
    );

    $row = rowFor(tabNamed($grid, 'resources'), Post::class);

    expect(cellFor($row, 'viewAny')->stance)->toBe(Stance::Granted)
        ->and(cellFor($row, 'delete')->stance)->toBe(Stance::Forbidden)
        ->and(cellFor($row, 'update')->stance)->toBe(Stance::Abstain);
});

test('a narrowed cell is marked and can still be changed', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => ['update' => 'granted']],
        [Post::class => ['update' => Narrowing::owned()]],
    );

    $cell = cellFor(rowFor(tabNamed($grid, 'resources'), Post::class), 'update');

    expect($cell->stance)->toBe(Stance::Granted)
        ->and($cell->isNarrowed())->toBeTrue()
        ->and($cell->isLocked())->toBeFalse()
        ->and($cell->isEditable())->toBeTrue();
});

test('a rule this screen cannot draw is shown and left exactly alone', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => ['update' => 'granted']],
        [Post::class => ['update' => Narrowing::tangled()]],
    );

    $cell = cellFor(rowFor(tabNamed($grid, 'resources'), Post::class), 'update');

    expect($cell->isLocked())->toBeTrue()
        ->and($cell->isEditable())->toBeFalse()
        ->and(rowFor(tabNamed($grid, 'resources'), Post::class)->editableActions())->not->toContain('update');
});

test('the tally counts what the tab grants, wildcard included', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => ['viewAny' => 'granted', StateKey::MANAGE => 'granted', 'delete' => 'forbidden']],
    );

    expect(tabNamed($grid, 'resources')->granted())->toBe(2);
});

test('a page and a widget are doors, with one action each and no wildcard', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->pages([Reports::class])->widgets([Summary::class]),
    );

    $page = tabNamed($grid, 'pages')->rows[0];

    expect(tabNamed($grid, 'pages')->matrix)->toBeFalse()
        ->and($page->model)->toBeNull()
        ->and($page->manage)->toBeNull()
        ->and($page->cells)->toHaveCount(1)
        ->and($page->cells[0]->action)->toBe(StateKey::DOOR)
        ->and(tabNamed($grid, 'widgets')->rows[0]->cells[0]->action)->toBe(StateKey::DOOR);
});

test('the door of the panel is a loose permission with a name of its own', function (): void {
    $grid = gridFor(Panel::make()->id('scratch'));

    expect(tabNamed($grid, 'loose')->rows[0]->key)->toBe('panel:scratch');
});

test('a tab with nothing in it is not drawn at all', function (): void {
    $grid = gridFor(Panel::make()->id('scratch'));

    expect(array_map(fn (Tab $tab): string => $tab->key, $grid->tabs))
        ->toBe(['resources', 'loose']);
});

test('the browser is handed the cycle order and the actions a wildcard reaches', function (): void {
    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    $alpine = $grid->alpine();

    expect($alpine['order'])->toBe(['abstain', 'granted', 'forbidden'])
        ->and($alpine['manage'])->toBe('manage')
        ->and($alpine['rows'][Post::class]['actions'])
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'])
        ->and($alpine['rows'][Post::class]['read'])->toBe(['viewAny', 'view']);
});

test('a role that holds everything does not read as a role that holds nothing', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        wider: ['*' => 'granted'],
    );

    $cell = cellFor(rowFor(tabNamed($grid, 'resources'), Post::class), 'viewAny');

    expect($cell->stance)->toBe(Stance::Abstain)
        ->and($cell->drawn())->toBe('broader')
        ->and($cell->broader())->toBe('granted')
        ->and($grid->wider)->toBe(['*' => 'granted']);
});

test('the wildcard on a row reaches the cells of that row and no other', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => [StateKey::MANAGE => 'granted']],
    );

    $post = rowFor(tabNamed($grid, 'resources'), Post::class);
    $role = rowFor(tabNamed($grid, 'resources'), roleClass());

    expect(cellFor($post, 'viewAny')->drawn())->toBe('broader')
        ->and(cellFor($post, StateKey::MANAGE)->drawn())->toBe('granted')
        ->and(cellFor($role, 'viewAny')->drawn())->toBe('abstain');
});

test('a forbidden wider rule is what the cell shows, not a granted one', function (): void {
    $grid = gridFor(
        Panel::make()->id('scratch')->resources([PostResource::class]),
        [Post::class => [StateKey::MANAGE => 'granted']],
        wider: ['*' => 'forbidden'],
    );

    expect(cellFor(rowFor(tabNamed($grid, 'resources'), Post::class), 'viewAny')->broader())
        ->toBe('forbidden');
});

test('a door is reached by a rule over everything too', function (): void {
    $grid = gridFor(Panel::make()->id('scratch'), wider: ['*' => 'granted']);

    expect(tabNamed($grid, 'loose')->rows[0]->cells[0]->drawn())->toBe('broader');
});

test('the columns follow the order the scope map declares, not the first policy walked', function (): void {
    config()->set('filament-warden.catalog.scopes', [
        'read' => ['view', 'viewAny'],
        'write' => ['update', 'create'],
        'withdraw' => ['deleteAny', 'delete'],
    ]);

    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    $actions = [];

    foreach ($grid->groups as $group) {
        foreach ($group->columns as $column) {
            $actions[] = $column->action;
        }
    }

    expect($actions)->toBe(['view', 'viewAny', 'update', 'create', 'deleteAny', 'delete']);
});

test('an action the map does not name still gets a column, after the ones it does', function (): void {
    config()->set('filament-warden.catalog.scopes', ['write' => ['create']]);

    $grid = gridFor(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect($grid->groups)->toHaveCount(1)
        ->and($grid->groups[0]->columns[0]->action)->toBe('create');
});

test('the legend names every drawing the grid uses', function (): void {
    $grid = gridFor(Panel::make()->id('scratch'));

    expect($grid->legend())->toHaveCount(7)
        ->and(array_column($grid->legend(), 'state'))
        ->toBe(['abstain', 'granted', 'forbidden', 'broader', 'abstain', 'granted', 'granted'])
        ->and(array_column($grid->legend(), 'locked'))
        ->toBe([false, false, false, false, false, false, true]);
});
