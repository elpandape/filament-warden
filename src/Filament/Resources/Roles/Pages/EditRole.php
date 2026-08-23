<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    /**
     * How many refused cells are named before the rest become a tally.
     *
     * Names then a tally, the shape `Holders::LABELS` already uses for the same
     * reason — a grid can refuse as many cells as it draws, and a notification
     * listing two hundred of them says nothing. The number is not the same one:
     * that is 10 account labels on a screen, this is 5 cells in a sentence.
     */
    private const int NAMED = 5;

    protected static string $resource = RoleResource::class;

    /**
     * The visibility is the guarantee, not decoration.
     *
     * A delete button asks `getDeleteAuthorizationResponse()`, which goes
     * straight to the policy: the resource's `canDelete()` — where the protected
     * list and the `roles.delete` rule live — is never on that path. Measured
     * with the plain `DeleteAction::make()` this page first carried: a protected
     * role was deleted outright from its own edit screen, and the next assertion
     * died with `ModelNotFoundException`. The description is `RolesTable`'s own
     * — the assignments go with it by a foreign key, below Eloquent and with no
     * event of their own, so this is the last moment anybody is told.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The delete takes its assignments with it below Eloquent, and
            // nothing in warden bumps the version for a write made through the
            // model layer: without the hook every check goes on answering the old
            // way, silently and with no expiry. Void on purpose — whatever
            // `after()` returns stands in for the action's own result.
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record))
                ->after(static function (): void {
                    Warden::refresh();
                }),
        ];
    }

    /**
     * The name of a protected role is put back from the record, always.
     *
     * Filament already drops it: `disabled()` also calls `saved(false)`, so the
     * field is not dehydrated and the key never arrives — measured, the forged
     * payload does not reach the store today. The check stays anyway, and
     * Filament's own source says to write it: the comment inside `disabled()`
     * spells out that the client can be made to send the field regardless, and
     * that authorization belongs in `mutateFormDataBeforeSave()`. Which name a
     * role carries is the whole of `roles.protected` — renaming it off the list
     * unprotects the role on the spot — so a guarantee about who can unlock the
     * most powerful role in the installation does not rest on how another
     * package derives a flag. It is the same reasoning as the `! isDisabled()`
     * check inside `PermissionGrid`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (RoleResource::isProtected($record)) {
            $data['name'] = $record->getAttribute('name');
        }

        return $data;
    }

    /**
     * What the save actually did, once somebody else can have been editing too.
     *
     * A save used to have one outcome. It now has three, and the notification is
     * the one place they are said — the grid itself says nothing about the save
     * and simply re-reads the store below, because a screen that draws a fact
     * AND hands the same fact to the browser is a screen with two versions of it
     * (AGENTS.md §6.24).
     *
     * `app()` is where the report is picked up rather than a property on this
     * page, because the field writes it from inside `saveRelationshipsUsing()`
     * and Filament rebuilds the schema — and every component in it — on each
     * request. The binding lives exactly as long as the request that made it.
     */
    protected function getSavedNotification(): ?Notification
    {
        $report = app()->bound(SaveReport::class) ? app(SaveReport::class) : null;

        if (! $report instanceof SaveReport || ! $report->metAnother()) {
            return parent::getSavedNotification();
        }

        if ($report->refused === []) {
            return Notification::make()
                ->success()
                ->title(__('filament-warden::ui.grid.concurrent.kept_title'))
                ->body(trans_choice('filament-warden::ui.grid.concurrent.kept', $report->preserved));
        }

        return Notification::make()
            ->warning()
            ->title(__('filament-warden::ui.grid.concurrent.refused_title'))
            ->body(__('filament-warden::ui.grid.concurrent.refused', ['cells' => $this->refusedCells($report)]));
    }

    /**
     * The screen tells the truth again, whatever the save met.
     *
     * Without this the person is left looking at what the store held when they
     * opened it, their next save collides on the very same cells, and nothing on
     * screen explains why. Re-filling also re-stamps the baseline, so the second
     * attempt starts from what is actually there.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }

    /**
     * The refused cells, in the grid's own words.
     *
     * Asking the catalogue again is free since `1.5.0` memoised it per panel,
     * and it is what keeps one cell from having two names on one screen.
     */
    private function refusedCells(SaveReport $report): string
    {
        // Typed `?Panel` and never null in fact: it throws when there is no
        // panel rather than answering nothing (AGENTS.md §6.12).
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();

        $catalog = Catalog::for($panel);

        $named = array_map(
            static fn (array $cell): string => GridView::cellLabel($catalog, $cell['row'], $cell['action']),
            array_slice($report->refused, 0, self::NAMED),
        );

        $rest = count($report->refused) - count($named);

        if ($rest > 0) {
            $named[] = __('filament-warden::ui.grid.concurrent.more', ['count' => $rest]);
        }

        return implode(', ', $named);
    }
}
