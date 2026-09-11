<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Concerns;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Explanation;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\Warden\Context;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Renderless;

/**
 * What the screen that hands permissions out and the one that only reads them
 * have in common: the view model, and the inspector behind it.
 *
 * The two differ in exactly one thing — whether there is an unsaved stance to
 * compare against — and that is the one method each of them answers for itself.
 */
trait DrawsThePermissionGrid
{
    /**
     * What the grid draws: the unsaved state on a form, the stored one on a page
     * that only reads.
     *
     * @return array<string, array<string, string>>
     */
    abstract protected function gridState(): array;

    /**
     * Whether the cells this render draws are controls.
     *
     * Neither screen can derive this from the other. A field is a control unless
     * the application disabled it — and only the field knows that — while a
     * screen that only reads never is, whatever it was handed.
     */
    abstract protected function gridInteracts(): bool;

    /**
     * Why one cell is the way it is — asked for, never volunteered.
     *
     * `explain()` costs three to five queries with no cache and no batching —
     * three when the check abstains, five when a role is what granted it, the
     * counts `ExplanationTest` pins — so a grid that explained every cell on
     * render would pay that for every cell on a screen nobody may ask a
     * question about. It answers one cell at a time, and `#[Renderless]` keeps
     * the click from re-rendering the whole page.
     *
     * The answer is about what is STORED — that is all `explain()` can read — so
     * the payload also carries the stance on screen when the two disagree. The
     * pending state arrives with this very call, before the method runs.
     *
     * An empty array is not an answer. It means the question could not be asked
     * of this grid at all — the inspector is switched off, or the cell is not in
     * the catalogue — and no rendered cell ever receives one.
     *
     * @return array<string, string|null>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function explainCell(string $row, string $action): array
    {
        if (! Config::enabled('grid.explain')) {
            return [];
        }

        $entry = $this->catalogEntryFor($row, $action);

        // Two guards and not one, because only one of them is a state a person
        // can see: a cell that is not in this catalogue is nobody's click,
        // while a role that has not been saved is the first screen a new admin
        // opens, and it gets an answer.
        if (! $entry instanceof Entry) {
            return [];
        }

        $role = $this->getRecord();

        if (! $role instanceof Model) {
            return Explanation::unsaved()->toPayload();
        }

        $stored = $this->storedState();

        return Explanation::of(
            role: $role,
            entry: $entry,
            rowKey: $row,
            action: $action,
            narrowed: $stored->narrowed(),
            onScreen: $this->onScreenStance($row, $action),
            stored: self::stanceIn($stored->stances, $row, $action),
            // Already read, and read once: this is the same `RoleState` the grid
            // was drawn from, so the inspector costs no query for the date.
            until: $stored->untils[$row][$action] ?? null,
        )->toPayload();
    }

    /**
     * What can be built into a condition for this cell, asked when the cell is
     * opened and not before: listing a table's columns costs a query — two on
     * sqlite — and nothing in Laravel caches one.
     *
     * What comes back is what is AVAILABLE and what is STORED. What is on screen
     * lives in the browser and does not travel: asking for it here would be
     * asking the server to confirm what the browser just wrote.
     *
     * @return array<string, mixed>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function narrowingFor(string $row, string $action): array
    {
        if (! Config::enabled('grid.constraints')) {
            return [];
        }

        $model = $this->catalogEntryFor($row, $action)?->model;

        if ($model === null) {
            return [
                'model' => null,
                'columns' => [],
                'booleans' => [],
                'authority' => [],
                'ownership' => [
                    'available' => false,
                    'reason' => self::line('filament-warden::ui.conditions.no_model'),
                ],
                'stored' => null,
            ];
        }

        $ownership = Ownership::of($model);

        return [
            'model' => $model,
            'columns' => Columns::of($model),
            'booleans' => Columns::booleans($model),
            'authority' => Columns::authority(),
            'ownership' => [
                'available' => $ownership->available,
                'reason' => self::ownershipReason($ownership, $model),
            ],
            'stored' => self::stored($this->storedState()->narrowings[$row][$action] ?? Narrowing::all()),
        ];
    }

    public function getGrid(): GridView
    {
        return GridView::for(
            $this->catalog(),
            $this->storedState(),
            $this->gridState(),
            $this->protectedRole(),
            $this->gridInteracts(),
        );
    }

    /**
     * @param  array<string, array<string, string>>  $stances
     */
    protected static function stanceIn(array $stances, string $row, string $action): Stance
    {
        return Stance::tryFrom($stances[$row][$action] ?? '') ?? Stance::Abstain;
    }

    /**
     * The stance on screen, when there is one that can differ from the store.
     */
    protected function onScreenStance(string $row, string $action): ?Stance
    {
        return null;
    }

    protected function storedState(): RoleState
    {
        $role = $this->getRecord();

        return $role instanceof Model
            ? RoleGrants::of($role, $this->catalog())
            : new RoleState;
    }

    protected function catalog(): Catalog
    {
        // The return type is nullable but the method throws when there is no
        // panel at all, which is the only way it could answer null.
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();

        return Catalog::for($panel);
    }

    /**
     * The catalogue entry a cell stands for.
     *
     * The wildcard column is the one that is not in the catalogue: it is warden's
     * `*` over the whole entity, offered by the grid rather than derived from a
     * policy, so it is built here.
     */
    protected function catalogEntryFor(string $row, string $action): ?Entry
    {
        foreach ($this->catalog()->entries as $entry) {
            if ($entry->model === null && $entry->name === $row && $action === StateKey::DOOR) {
                return $entry;
            }

            if ($entry->model === $row && $entry->name === $action) {
                return $entry;
            }

            if ($entry->model === $row && $action === StateKey::MANAGE) {
                return new Entry('*', $entry->entityType, $entry->model, Scope::Write, Origin::Model);
            }
        }

        return null;
    }

    /**
     * Why the reach cannot be offered, in the words of the actual refusal.
     *
     * A model whose table lacks the column and an installation that resolves no
     * ownership at all are two different things to go and fix, and for the
     * second there is no column to name.
     *
     * @param  class-string<Model>  $model
     */
    private static function ownershipReason(Ownership $ownership, string $model): ?string
    {
        if ($ownership->available) {
            return null;
        }

        if (! $ownership->resolved) {
            return self::line('filament-warden::ui.conditions.no_ownership_resolver');
        }

        return self::line('filament-warden::ui.conditions.no_ownership', [
            'table' => new $model()->getTable(),
            'column' => $ownership->column ?? '',
        ]);
    }

    /**
     * @return array{mode: string, preview: string, locked: bool, note: string|null}
     */
    private static function stored(Narrowing $narrowing): array
    {
        return [
            'mode' => $narrowing->shape->value,
            'preview' => $narrowing->preview(
                self::line('filament-warden::ui.conditions.authority'),
                self::line('filament-warden::ui.conditions.and'),
                self::line('filament-warden::ui.conditions.or'),
            ),
            'locked' => ! $narrowing->isEditable(),
            'note' => $narrowing->reason === null ? null : self::line('filament-warden::ui.conditions.locked.'.$narrowing->reason),
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        return Line::of($key, $replace);
    }

    /**
     * Whether the installation protects the role on screen.
     *
     * Not the same question as whether this screen can change it. On the form
     * the two coincide, because the field is disabled for exactly this reason;
     * on a screen that changes nothing they never do, so one cannot be read
     * off the other.
     *
     * The class check is not ceremony: this trait backs `PermissionGrid` and
     * `PermissionGridEntry`, either of which may be handed any record, and
     * `roles.protected` matches by `name` — wrong on another class that has a
     * `name` column, fatal under `Model::shouldBeStrict()` on one that does
     * not. It asks the configured role class, as warden does everywhere else.
     */
    private function protectedRole(): bool
    {
        $role = $this->getRecord();
        $roleClass = Context::resolve()->roleClass();

        return $role instanceof $roleClass && RoleResource::isProtected($role);
    }
}
