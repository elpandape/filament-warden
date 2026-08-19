<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Grants\Assignment;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Database\Eloquent\Model;

/**
 * Handing roles to an account, from the account's own screen.
 *
 * A field and not a relation manager, for two measured reasons. A package cannot
 * attach a relation manager to a resource it does not own — `getRelations()` is
 * a concrete static and nothing can write to it — and the actions of one,
 * `AttachAction` and `DetachAction`, **check no policy at all** in Filament 5.7:
 * they are gated only by `isReadOnly()`, which is false on any edit page.
 *
 * And it does not use `->relationship()` either, which is what most packages
 * reach for: that saves through `sync()`, and `sync()`, `attach()` and `detach()`
 * all skip warden's cache bump — a role handed out that way goes on answering
 * the old way, silently. The whole write goes through the fluent API instead.
 *
 * The consuming application adds one line to its own account form:
 *
 *     RoleAssignment::make('roles')->columnSpanFull(),
 */
final class RoleAssignment extends CheckboxList
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-warden::ui.relations.roles.label'));
        $this->helperText(__('filament-warden::ui.relations.roles.help'));

        $this->options(static fn (): array => Assignment::options());

        $this->descriptions(static fn (RoleAssignment $component): array => $component->reasons());

        // Shown and locked, never hidden: what an account holds is worth seeing
        // even by somebody who may not change it.
        $this->disableOptionWhen(static fn (RoleAssignment $component, mixed $value): bool => ! $component->offers($value));

        $this->searchable();

        // "Select all" would hand out every role in the installation with one
        // click, and the ones it may not touch would be dropped server-side in
        // silence. Two clicks are cheaper than that.
        $this->bulkToggleable(false);

        // Not an optimisation: the save path ends in `$record->update($data)`,
        // and a list of role keys left in there would hit mass assignment on a
        // model this package does not own.
        $this->dehydrated(false);
        $this->validatedWhenNotDehydrated(false);

        $this->afterStateHydrated(static function (RoleAssignment $component): void {
            $account = $component->getRecord();

            $component->state($account instanceof Model ? Assignment::of($account) : []);
        });

        $this->saveRelationshipsUsing(static function (RoleAssignment $component): void {
            $account = $component->getRecord();

            // A disabled option reaches the state exactly like a disabled field
            // does, so the guarantee is written again inside `apply()`. This is
            // the outer half of it.
            if ((! $component->isDisabled()) && $account instanceof Model) {
                Assignment::apply($account, $component->getState());
            }
        });
    }

    /**
     * Why a role is locked, when it is.
     *
     * @return array<int|string, string>
     */
    public function reasons(): array
    {
        return Assignment::descriptions($this->account());
    }

    /**
     * Whether this screen may hand that role out at all.
     */
    public function offers(mixed $value): bool
    {
        return Assignment::offers($this->account(), $value);
    }

    /**
     * A schema's record may be an array rather than a model — a form filled from
     * plain data has no account behind it, and neither has a create screen.
     */
    private function account(): ?Model
    {
        $record = $this->getRecord();

        return $record instanceof Model ? $record : null;
    }
}
