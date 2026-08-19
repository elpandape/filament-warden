<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Concerns\DrawsThePermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Forms\Components\Field;
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
    use DrawsThePermissionGrid;

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
            $stored = $component->storedState();

            $component->state([
                'stances' => $stored->stances,
                'narrowing' => self::payloadOf($stored->narrowings),
            ]);
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
                RoleGrants::apply(
                    $role,
                    $component->catalog(),
                    $component->gridState(),
                    $component->gridNarrowings(),
                );
            }
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function gridState(): array
    {
        return State::stances($this->getState());
    }

    protected function onScreenStance(string $row, string $action): Stance
    {
        return self::stanceIn($this->gridState(), $row, $action);
    }

    /**
     * A cell that is not in the map reaches every row, so only the narrowed ones
     * travel: this whole map goes to the browser on every render.
     *
     * And only the ones this screen can draw. A rule it cannot is drawn from the
     * server once and never touched again — putting it in the state would offer
     * the browser something to edit that nothing would accept back.
     *
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @return array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>
     */
    private static function payloadOf(array $narrowings): array
    {
        $payload = [];

        foreach ($narrowings as $row => $actions) {
            foreach ($actions as $action => $narrowing) {
                if ($narrowing->isNarrowed() && $narrowing->isEditable()) {
                    $payload[$row][$action] = $narrowing->toPayload();
                }
            }
        }

        return $payload;
    }

    /**
     * How far each cell reaches on screen. Only the field has one: a screen that
     * only reads has nothing pending.
     *
     * With the builder switched off this answers null rather than an empty map,
     * and the difference is the whole point: an empty map would read as "every
     * cell reaches every row" and widen every narrowed rule on the first save.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function gridNarrowings(): ?array
    {
        return Config::enabled('grid.constraints') ? State::narrowings($this->getState()) : null;
    }
}
