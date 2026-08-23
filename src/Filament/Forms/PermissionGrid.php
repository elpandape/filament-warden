<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Filament\Concerns\DrawsThePermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
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
            // The same payload the screen that only reads renders as a literal:
            // one shape, and one place it is worked out.
            $payload = $component->storedState()->toPayload();

            // And a second, untouched copy of it: what this screen was showing
            // when it opened. The save needs it to tell what this person moved
            // from what the store moved under them, and it cannot be worked out
            // later — by then `storedState()` answers about now. It cannot live
            // on the component either, because Filament rebuilds the schema on
            // every request. So it travels in the state, which is the only thing
            // that makes the round trip.
            //
            // The browser never writes it: `permission-grid.js` rebuilds state
            // with `{ ...this.state, stances: … }`, a spread that carries keys
            // it knows nothing about. That is load-bearing, not incidental, and
            // `verify/verify-baseline-survives.mjs` is what says so.
            $component->state($payload + ['baseline' => $payload]);
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
                $report = RoleGrants::apply(
                    $role,
                    $component->catalog(),
                    $component->gridState(),
                    $component->gridNarrowings(),
                    $component->gridBaseline(),
                );

                // One channel, and this is it. The page reads the report here to
                // decide what its notification says; the grid itself says nothing
                // about the save and simply re-reads the store afterwards. A
                // second copy inside the state would be the same screen telling
                // the same fact twice, which is how the two halves drift.
                app()->instance(SaveReport::class, $report);
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

    /**
     * The application decides, by disabling the field or not.
     */
    protected function gridInteracts(): bool
    {
        return ! $this->isDisabled();
    }

    protected function onScreenStance(string $row, string $action): Stance
    {
        return self::stanceIn($this->gridState(), $row, $action);
    }

    /**
     * What the store held when this screen opened, or null on a screen that
     * never stamped one — a record being created, or a state written by hand.
     * Null asks the save to treat every cell as touched, which is what it did
     * before there was a baseline.
     *
     * @return array{stances?: mixed, narrowing?: mixed}|null
     */
    private function gridBaseline(): ?array
    {
        $state = $this->getState();
        $baseline = is_array($state) ? ($state['baseline'] ?? null) : null;

        if (! is_array($baseline)) {
            return null;
        }

        /** @var array{stances?: mixed, narrowing?: mixed} $baseline */
        return $baseline;
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
