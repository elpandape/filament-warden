<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Grants\Explanation;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Panel;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Renderless;

/**
 * The grid, as a form field.
 *
 * Its state is not a column of the role, so three things follow and all three
 * are deliberate: it fills itself from the store rather than from the record,
 * it stays out of the dehydrated data so mass assignment never sees it, and it
 * persists through the relationship hook — which is exactly what a first-party
 * `CheckboxList::relationship()` does.
 */
final class PermissionGrid extends Field
{
    protected string $view = 'filament-warden::forms.permission-grid';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);

        // Not an optimisation: the save path ends in `$record->update($data)`,
        // and a matrix left in there would hit mass assignment.
        $this->dehydrated(false);
        $this->validatedWhenNotDehydrated(false);

        $this->afterStateHydrated(static function (self $component): void {
            $component->state($component->stored()->stances);
        });

        $this->saveRelationshipsUsing(static function (self $component): void {
            $role = $component->getRecord();

            // Filament already skips a disabled field here, because `isSaved()` is
            // false for one — measured, not assumed. The check stays anyway: a
            // locked grid is a guarantee about who can take power away, and it
            // should not rest on how another package derives a flag. The browser's
            // payload still reaches the field's state even when it is disabled, so
            // this is the last thing standing between it and the store.
            if ((! $component->isDisabled()) && $role instanceof Model) {
                RoleGrants::apply($role, $component->catalog(), $component->desired());
            }
        });
    }

    /**
     * Why one cell is the way it is — asked for, never volunteered.
     *
     * `explain()` costs three to seven queries with no cache and no batching, so
     * a grid that explained every cell on render would spend more than a hundred
     * on a screen the person may never ask a question about. It is answered one
     * cell at a time, and `#[Renderless]` keeps the click from re-rendering the
     * whole page.
     *
     * The answer is about what is STORED — that is all `explain()` can read — so
     * the payload also carries the stance on screen when the two disagree. The
     * pending state arrives with this very call, before the method runs.
     *
     * @return array<string, string|null>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function explainCell(string $row, string $action): array
    {
        $role = $this->getRecord();
        $entry = $this->entryFor($row, $action);

        if (! $role instanceof Model || ! $entry instanceof Entry) {
            return [];
        }

        $stored = RoleGrants::of($role, $this->catalog());

        return Explanation::of(
            role: $role,
            entry: $entry,
            rowKey: $row,
            action: $action,
            narrowed: $stored->narrowed,
            onScreen: $this->stanceIn($this->desired(), $row, $action),
            stored: $this->stanceIn($stored->stances, $row, $action),
        )->toPayload();
    }

    public function getGrid(): GridView
    {
        $stored = $this->stored();

        return GridView::for($this->catalog(), $this->desired(), $stored->narrowed, $stored->wider);
    }

    /**
     * The catalogue entry a cell stands for.
     *
     * The wildcard column is the one that is not in the catalogue: it is warden's
     * `*` over the whole entity, offered by the grid rather than derived from a
     * policy, so it is built here.
     */
    private function entryFor(string $row, string $action): ?Entry
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
     * @param  array<string, array<string, string>>  $stances
     */
    private function stanceIn(array $stances, string $row, string $action): Stance
    {
        return Stance::tryFrom($stances[$row][$action] ?? '') ?? Stance::Abstain;
    }

    /**
     * What the store says today. A role that does not exist yet says nothing.
     */
    private function stored(): RoleState
    {
        $role = $this->getRecord();

        return $role instanceof Model
            ? RoleGrants::of($role, $this->catalog())
            : new RoleState;
    }

    private function catalog(): Catalog
    {
        // The return type is nullable but the method throws when there is no
        // panel at all, which is the only way it could answer null.
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();

        return Catalog::for($panel);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function desired(): array
    {
        return State::normalize($this->getState());
    }
}
