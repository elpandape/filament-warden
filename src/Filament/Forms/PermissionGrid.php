<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Filament\Concerns\DrawsThePermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\State;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
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

    /**
     * How many refused cells are named before the rest become a tally.
     *
     * Names then a tally, the shape `Holders::LABELS` uses for the same reason:
     * a grid can refuse as many cells as it draws, and a notification listing
     * two hundred of them says nothing.
     */
    private const int NAMED = 5;

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

            // And an untouched copy: what this screen showed when it opened, so
            // the save can tell what this person moved from what the store
            // moved under them. It travels in the state because nothing else
            // makes the round trip: by the save, `storedState()` answers about
            // the present, and Filament rebuilds the schema on every request.
            //
            // The browser never writes it: `permission-grid.js` rebuilds state
            // with `{ ...this.state, stances: … }`, a spread that carries keys
            // it knows nothing about. That is load-bearing, and
            // `verify/verify-baseline-survives.mjs` pins it.
            $component->state($payload + ['baseline' => $payload]);
        });

        $this->saveRelationshipsUsing(static function (self $component): void {
            $role = $component->getRecord();

            // Filament already skips a disabled field here, because `isSaved()` is
            // false for one. The check stays anyway: a locked grid is a guarantee
            // about who can take power away, and it should not rest on how
            // another package derives a flag. The browser's payload still reaches
            // the field's state even when it is disabled, so this is the last
            // thing standing between it and the store.
            if ((! $component->isDisabled()) && $role instanceof Model) {
                $report = RoleGrants::apply(
                    $role,
                    $component->catalog(),
                    $component->gridState(),
                    $component->gridNarrowings(),
                    $component->gridBaseline(),
                    $component->gridUntils(),
                );

                // Bound for the rest of the request, so `savedBody()` — or a
                // page this package does not own — can say more than the
                // field's own notices below.
                app()->instance(SaveReport::class, $report);

                // The screen tells the truth again, on ANY page. `EditRole` gets
                // there by re-filling the whole form afterwards; a page this
                // package does not own has no such hook, and without this a
                // refused cell stays refused for good — its next save reads as
                // touched against a baseline that is still the one from before,
                // and nothing on screen explains why. Safe because the field is
                // `dehydrated(false)`: rewritten state can never reach
                // `$record->update()`.
                $payload = $component->storedState()->toPayload();

                $component->state($payload + ['baseline' => $payload]);

                $component->announce($report);
            }
        });
    }

    /**
     * What a save wrote, split by the stance each cell was moved to.
     *
     * A page hangs this on its own "Saved" rather than the field sending a
     * second toast, because an ordinary save is the common case and two
     * notifications for it would be noise. The exceptional halves stay with
     * `announce()`: only the field can name a cell, and only it knows the ones
     * a save met, refused or left unwritten.
     *
     * Static, and reading the report off the container, because the pages that
     * want it are not the field and Filament rebuilds every component in the
     * schema each request — the binding lives exactly as long as the request
     * that made it. That is also the recipe the README hands to a page this
     * package does not own.
     *
     * Only the counts above zero become clauses, and no clause means no
     * sentence: a save that changed nothing says ABSENT rather than a row of
     * zeroes. A second guard on `written` would defend nothing — every change
     * carries one of the three stances, so the counts sum to `written` by
     * construction.
     */
    public static function savedBody(): ?string
    {
        $report = app()->bound(SaveReport::class) ? app(SaveReport::class) : null;

        if (! $report instanceof SaveReport) {
            return null;
        }

        $clauses = [];

        foreach (['granted', 'forbidden', 'revoked'] as $stance) {
            $count = $report->{$stance};

            if ($count > 0) {
                $clauses[] = trans_choice("filament-warden::ui.grid.saved.{$stance}", $count);
            }
        }

        return $clauses === [] ? null : implode(', ', $clauses);
    }

    /**
     * What the save did, said once and in the grid's own words.
     *
     * Sent through `afterCommit` and not straight away, because this runs
     * inside `getState()` — before the record is updated, and inside whatever
     * transaction the page opened. Announcing there would name a save a later
     * failure can still undo. With no transaction open the callback runs on the
     * spot, which is the common case: a panel opts into transactions and does
     * not get them by default.
     */
    public function announce(SaveReport $report): void
    {
        if (! $report->metAnother() && $report->unresolved === [] && $report->lapsed === [] && $report->impossible === []) {
            return;
        }

        if ($report->unresolved !== []) {
            $this->sendAfterCommit(
                Notification::make()
                    ->warning()
                    ->title(__('filament-warden::ui.grid.tangled.title'))
                    ->body(__('filament-warden::ui.grid.tangled.body', [
                        'cells' => $this->namedCells($report->unresolved),
                    ])),
            );
        }

        // Its own notice rather than a line inside the tangled one. The two
        // report the same outcome — asked for, not written — for opposite
        // reasons, and a person reading "the store holds two rules for these"
        // about a date they mistyped goes looking for a problem that is not
        // there.
        if ($report->lapsed !== []) {
            $this->sendAfterCommit(
                Notification::make()
                    ->warning()
                    ->title(__('filament-warden::ui.grid.lapsed.title'))
                    ->body(__('filament-warden::ui.grid.lapsed.body', [
                        'cells' => $this->namedCells($report->lapsed),
                    ])),
            );
        }

        // Its own notice again, and for the sharpest reason of the three: this
        // one is the difference between granting nothing and granting everything.
        // Warden refuses the rule; the plain grant underneath it does not refuse
        // itself, so saying "left alone" is only true because this package
        // refused first.
        if ($report->impossible !== []) {
            $this->sendAfterCommit(
                Notification::make()
                    ->danger()
                    ->title(__('filament-warden::ui.grid.impossible.title'))
                    ->body(__('filament-warden::ui.grid.impossible.body', [
                        'cells' => $this->namedCells($report->impossible),
                    ])),
            );
        }

        if (! $report->metAnother()) {
            return;
        }

        $notification = $report->refused === []
            ? Notification::make()
                ->success()
                ->title(__('filament-warden::ui.grid.concurrent.kept_title'))
                ->body(trans_choice('filament-warden::ui.grid.concurrent.kept', $report->preserved))
            : Notification::make()
                ->warning()
                ->title(__('filament-warden::ui.grid.concurrent.refused_title'))
                ->body(__('filament-warden::ui.grid.concurrent.refused', ['cells' => $this->refusedCells($report)]));

        $this->sendAfterCommit($notification);
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function gridState(): array
    {
        return State::stances($this->getState());
    }

    protected function gridInteracts(): bool
    {
        return ! $this->isDisabled();
    }

    protected function onScreenStance(string $row, string $action): Stance
    {
        return self::stanceIn($this->gridState(), $row, $action);
    }

    private function refusedCells(SaveReport $report): string
    {
        return $this->namedCells($report->refused);
    }

    /**
     * A list of cells, in the grid's own words.
     *
     * Asking the catalogue again costs nothing, because it is memoised per
     * panel, and it is what keeps one cell from having two names on one screen.
     * Shared by every list a save can report, which name cells for different
     * reasons and must not name them differently.
     *
     * @param  list<array{row: string, action: string}>  $cells
     */
    private function namedCells(array $cells): string
    {
        $catalog = $this->catalog();

        $named = array_map(
            fn (array $cell): string => GridView::cellLabel($catalog, $cell['row'], $cell['action']),
            array_slice($cells, 0, self::NAMED),
        );

        $rest = count($cells) - count($named);

        if ($rest > 0) {
            $named[] = (string) __('filament-warden::ui.grid.concurrent.more', ['count' => $rest]);
        }

        return implode(', ', $named);
    }

    /**
     * A notification the grid sends once the write it describes is committed.
     *
     * `Model::getConnection()` returns the concrete `Connection`, which declares
     * `afterCommit()`; `Builder::getConnection()` is typed `ConnectionInterface`
     * and does not.
     */
    private function sendAfterCommit(Notification $notification): void
    {
        (new (Context::resolve()->grantClass()))->getConnection()->afterCommit(
            static function () use ($notification): void {
                $notification->send();
            },
        );
    }

    /**
     * What the store held when this screen opened, or null when there is none.
     *
     * A record being created is NOT that case, which looks like it should be:
     * `afterStateHydrated` runs there too and stamps an empty baseline, so a
     * create page takes the three-way path over an empty store. Null is for a
     * state written by hand, and asks the save to treat every cell as touched.
     *
     * A forged baseline cannot escalate: it decides WHETHER a change is written,
     * never what — the stance and reach come from the payload — so the worst a
     * doctored one does is suppress the forger's own save.
     *
     * @return array{stances?: mixed, narrowing?: mixed, until?: mixed, inherited?: mixed}|null
     */
    private function gridBaseline(): ?array
    {
        $state = $this->getState();
        $baseline = is_array($state) ? ($state['baseline'] ?? null) : null;

        if (! is_array($baseline)) {
            return null;
        }

        /** @var array{stances?: mixed, narrowing?: mixed, until?: mixed, inherited?: mixed} $baseline */
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

    /**
     * When each cell stops, on screen.
     *
     * Null and an empty map are different answers here, exactly as they are for
     * the reach: null keeps every date the store holds, and an empty map clears
     * them all. So a screen with the feature switched off answers null, and the
     * store keeps its dates whatever the browser sends back. And a map is never
     * read as null: a cell missing from it is a date the person cleared, and
     * reading it as kept would make that impossible to save.
     *
     * @return array<string, array<string, CarbonImmutable>>|null
     */
    private function gridUntils(): ?array
    {
        return Config::enabled('grid.expiry') ? State::untils($this->getState()) : null;
    }
}
