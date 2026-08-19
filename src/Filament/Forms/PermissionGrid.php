<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

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

            if ($role instanceof Model) {
                RoleGrants::apply($role, $component->catalog(), $component->desired());
            }
        });
    }

    public function getGrid(): GridView
    {
        $stored = $this->stored();

        return GridView::for($this->catalog(), $this->desired(), $stored->narrowed, $stored->wider);
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
