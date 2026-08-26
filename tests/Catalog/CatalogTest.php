<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\CommentResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\LedgerResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\PostResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\TagResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Policies\PostPolicy;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Panel;

/**
 * `Catalog::for()` is memoised per panel id, and three traps about that memo
 * live here rather than as prose inside a test body.
 *
 * `PostPolicy::$instantiations` is the counter the suite watches instead of one
 * invented for `src/`: `Gate::getPolicyFor()` resolves through the container
 * fresh on every call, with no cache of its own (AGENTS.md §6.9), so a policy's
 * own constructor already counts every time something reflected it. Read by
 * "a policy is reflected once no matter how many times the same panel is
 * asked for".
 *
 * A memo keyed by id alone would hand a caller the first panel's rows when it
 * asks about a second, unrelated object that happens to share that id — and
 * this is not hypothetical for this file's own plumbing: every test here
 * builds a fresh `Panel::make()->id('scratch')`, the same id, dozens of times
 * over. `Catalog::read()` compares the stored panel with `===` on every read
 * to close it. Fixed by "a second panel object sharing an old id gets its own
 * rows, not the first one's".
 *
 * `catalog.models` and `catalog.custom` are read once per build and never
 * re-read on their own: the same decision `Conditions\Columns` already made
 * for a model's schema — config does not change while the process serving it
 * keeps running, so nothing here notices a change without an explicit
 * `Catalog::forget()`. Documented, not merely asserted, by "a config change is
 * invisible to an already-built catalogue until it is forgotten".
 */
pest()->extend(TestCase::class);

/**
 * @param  list<Entry>  $entries
 * @return list<string>
 */
function namesFor(array $entries, ?string $model): array
{
    return array_values(array_map(
        static fn (Entry $entry): string => $entry->name,
        array_filter($entries, static fn (Entry $entry): bool => $entry->model === $model),
    ));
}

/**
 * @param  list<Entry>  $entries
 * @return list<Entry>
 */
function entriesFrom(array $entries, Origin $origin): array
{
    return array_values(array_filter($entries, static fn (Entry $entry): bool => $entry->origin === $origin));
}

test('a resource contributes exactly what its model policy declares', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(namesFor($catalog->entries, Post::class))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('a resource whose model has no policy contributes nothing, and says nothing about it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([CommentResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Resource))->toBeEmpty();
});

test('a permission derived from a policy carries the morph alias the store will write', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    $entry = entriesFrom($catalog->entries, Origin::Resource)[0];

    expect($entry->entityType)->toBe(new Post()->getMorphClass())
        ->and($entry->model)->toBe(Post::class)
        ->and($entry->source)->toBe(PostResource::class);
});

test('each action lands in the scope the map gives it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    $scopes = [];

    foreach ($catalog->entries as $entry) {
        if ($entry->model === Post::class) {
            $scopes[$entry->name] = $entry->scope;
        }
    }

    expect($scopes)->toBe([
        'viewAny' => Scope::Read,
        'view' => Scope::Read,
        'create' => Scope::Write,
        'update' => Scope::Write,
        'delete' => Scope::Withdraw,
        'deleteAny' => Scope::Withdraw,
    ]);
});

test('a page is a loose permission with no entity, because a page is not a model', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->pages([Reports::class]));

    $page = entriesFrom($catalog->entries, Origin::Page)[0];

    expect($page->name)->toBe('page:'.Reports::class)
        ->and($page->entityType)->toBeNull()
        ->and($page->model)->toBeNull()
        ->and($page->scope)->toBe(Scope::Read)
        ->and($page->source)->toBe(Reports::class);
});

test('a widget is a loose permission too', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->widgets([Summary::class]));

    $widget = entriesFrom($catalog->entries, Origin::Widget)[0];

    expect($widget->name)->toBe('widget:'.Summary::class)
        ->and($widget->entityType)->toBeNull()
        ->and($widget->scope)->toBe(Scope::Read);
});

test('a widget registered through its configuration is catalogued by its class', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->widgets([Summary::make()]));

    expect(namesFor($catalog->entries, null))->toContain('widget:'.Summary::class);
});

test('a widget a resource brings with it is catalogued as well', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Widget))->toHaveCount(1)
        ->and(entriesFrom($catalog->entries, Origin::Widget)[0]->name)->toBe('widget:'.Summary::class);
});

test('the door of the panel is in the catalogue, or nobody could ever be given it', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $door = entriesFrom($catalog->entries, Origin::Panel)[0];

    expect($door->name)->toBe('panel:scratch')
        ->and($door->entityType)->toBeNull()
        ->and($door->scope)->toBe(Scope::Read);
});

test('the two models this package owns are in the catalogue before it owns a screen', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    expect(namesFor($catalog->entries, roleClass()))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'])
        ->and(namesFor($catalog->entries, permissionClass()))
        ->toBe(['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny']);
});

test('the two models this package owns carry the morph alias warden writes for them', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $role = entriesFrom($catalog->entries, Origin::Model)[0];

    expect($role->entityType)->toBe('warden.role')
        ->and($role->source)->toBeNull();
});

test('a model an application declares by config needs no resource to be catalogued', function (): void {
    config()->set('filament-warden.catalog.models', [Tag::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch'));

    expect(namesFor($catalog->entries, Tag::class))->toBe(['viewAny', 'view']);
});

test('a loose permission an application declares arrives with the scope it declared', function (): void {
    config()->set('filament-warden.catalog.custom', [
        'export-reports' => 'read',
        'close-month' => 'nonsense',
    ]);

    $catalog = Catalog::for(Panel::make()->id('scratch'));

    $custom = [];

    foreach (entriesFrom($catalog->entries, Origin::Custom) as $entry) {
        $custom[$entry->name] = $entry->scope;
    }

    expect($custom)->toBe([
        'export-reports' => Scope::Read,
        'close-month' => Scope::Write,
    ]);
});

test('the same permission reached twice is catalogued once', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(namesFor($catalog->entries, Post::class))->toHaveCount(6);
});

test('a resource wins the deduplication, so an entry knows the screen it belongs to', function (): void {
    config()->set('filament-warden.catalog.models', [Post::class]);

    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([PostResource::class]));

    expect(entriesFrom($catalog->entries, Origin::Resource))->toHaveCount(6)
        ->and(namesFor(entriesFrom($catalog->entries, Origin::Model), Post::class))->toBeEmpty();
});

test('a relation manager that says where it points brings its model in', function (): void {
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([LedgerResource::class]));

    $names = [];

    foreach ($catalog->entries as $entry) {
        if ($entry->model === Tag::class) {
            $names[] = $entry->name;
        }
    }

    expect($names)->toContain('viewAny')
        ->and($names)->toContain('view');
});

test('a relation manager that says nothing is never resolved, whatever it costs', function (): void {
    // `Ledger::explosive()` throws. Reaching its model would mean running it, and
    // the suite would die here rather than in somebody's console.
    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([LedgerResource::class]));

    expect($catalog->entries)->not->toBeEmpty();
});

test('a resource pointing at a class nobody wrote does not take the grid with it', function (): void {
    $ghost = new class extends Filament\Resources\Resource {};

    $catalog = Catalog::for(Panel::make()->id('scratch')->resources([$ghost::class, PostResource::class]));

    $models = [];

    foreach ($catalog->entries as $entry) {
        if ($entry->model !== null) {
            $models[] = $entry->model;
        }
    }

    expect($models)->toContain(Post::class);
});

test('a name this package minted reads back into something a person recognises', function (string $name, ?string $title): void {
    expect(PermissionName::title($name))->toBe($title);
})->with([
    'a widget' => ['widget:Filament\\Widgets\\AccountWidget', 'View Account Widget'],
    'a page' => ['page:App\\Filament\\Pages\\Reports', 'Access Reports'],
    'the panel door' => ['panel:admin', 'Access the Admin panel'],
    'an action' => ['viewAny', null],
    'a loose name of the application' => ['export-reports', null],
]);

test('a name this package never minted gets wardens own answer, not an empty one', function (): void {
    // One question, two families of row. A door this package mints has four
    // shapes because two older versions of this package and one of warden wrote
    // different ones; everything else has warden's title for the row as it
    // stands, which is the same comparison the permission form was making on its
    // own — plus the one warden wrote before 2.0, when the two differ. They do
    // not for `view`, and the list says so by not repeating it.
    expect(PermissionName::generated('export-reports'))->toBe(['Export reports'])
        ->and(PermissionName::generated('view', 'post'))->toBe([PermissionTitle::generate('view', 'post', null, false)])
        ->and(PermissionName::generated('page:App\\Filament\\Pages\\Reports'))
        ->toBe([
            'Page: app\\ filament\\ pages\\ reports',
            'Page:App\\Filament\\Pages\\Reports',
            'Reports',
            'Access Reports',
        ]);
});

test('the verb is the question filament asks: a widget is seen, a page is entered', function (): void {
    expect(PermissionName::title('widget:App\\Filament\\Widgets\\Summary'))->toStartWith('View ')
        ->and(PermissionName::title('page:App\\Filament\\Pages\\Reports'))->toStartWith('Access ')
        ->and(PermissionName::title('panel:admin'))->toStartWith('Access ');
});

test('a policy is reflected once no matter how many times the same panel is asked for', function (): void {
    PostPolicy::$instantiations = 0;
    $panel = Panel::make()->id('scratch')->resources([PostResource::class]);

    Catalog::for($panel);
    Catalog::for($panel);
    Catalog::for($panel);

    expect(PostPolicy::$instantiations)->toBe(1);
});

test("a second panel object sharing an old id gets its own rows, not the first one's", function (): void {
    $first = Panel::make()->id('scratch')->resources([PostResource::class]);
    $second = Panel::make()->id('scratch')->resources([TagResource::class]);

    Catalog::for($first);
    $catalog = Catalog::for($second);

    expect(namesFor($catalog->entries, Post::class))->toBeEmpty()
        ->and(namesFor($catalog->entries, Tag::class))->toBe(['viewAny', 'view']);
});

test('a config change is invisible to an already-built catalogue until it is forgotten', function (): void {
    $panel = Panel::make()->id('scratch');

    expect(namesFor(Catalog::for($panel)->entries, Tag::class))->toBeEmpty();

    config()->set('filament-warden.catalog.models', [Tag::class]);

    expect(namesFor(Catalog::for($panel)->entries, Tag::class))->toBeEmpty();

    Catalog::forget();

    expect(namesFor(Catalog::for($panel)->entries, Tag::class))->toBe(['viewAny', 'view']);
});
