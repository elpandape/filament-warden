<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
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
    /**
     * Where the untouched copy of the store's answer sits inside the page's own
     * state array. Namespaced because that array is the application's.
     */
    public const string BASELINE = '__filament_warden_roles_baseline';

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

            $component->fillFrom($account instanceof Model ? Assignment::of($account) : []);
        });

        $this->saveRelationshipsUsing(static function (RoleAssignment $component): void {
            $account = $component->getRecord();

            // A disabled option reaches the state exactly like a disabled field
            // does, so the guarantee is written again inside `apply()`. This is
            // the outer half of it.
            if ((! $component->isDisabled()) && $account instanceof Model) {
                $report = Assignment::apply($account, $component->getState(), $component->baseline());

                // The screen tells the truth again, and the next save starts from
                // what is actually there rather than colliding on the same roles.
                $component->fillFrom(Assignment::of($account));

                $component->announce($report);
            }
        });
    }

    /**
     * The list, and beside it an untouched copy of what the store said.
     *
     * A `CheckboxList` has ONE state slot and it holds the set, so the copy
     * cannot go in it. It goes in the page's own state array as a sibling key —
     * measured before it was relied on: it survives the mount, a click and the
     * save, and `Schema::getState()` does not return it, so it can never reach
     * `$record->update()`.
     *
     * That array belongs to the application, so the key is namespaced and this
     * is the only place that writes it. If a page has no `$data` to write into,
     * there is simply no baseline and the save behaves as it did before there
     * was one.
     *
     * @param  list<int|string>  $held
     */
    public function fillFrom(array $held): void
    {
        $this->state($held);

        $livewire = $this->getLivewire();

        if (property_exists($livewire, 'data') && is_array($livewire->data)) {
            $livewire->data[self::BASELINE] = $held;
        }
    }

    /**
     * @return list<int|string>|null
     */
    public function baseline(): ?array
    {
        $livewire = $this->getLivewire();

        if (! property_exists($livewire, 'data') || ! is_array($livewire->data)) {
            return null;
        }

        $baseline = $livewire->data[self::BASELINE] ?? null;

        if (! is_array($baseline)) {
            return null;
        }

        return array_values(array_filter(
            $baseline,
            static fn (mixed $key): bool => is_int($key) || is_string($key),
        ));
    }

    /**
     * What the save met, said by the field itself.
     *
     * The grid can leave this to `EditRole`, which owns its page and replaces
     * the "Saved" notification outright. This field is one line inside a form
     * the application wrote, so there is no notification of ours to replace —
     * and saying nothing would be the silence this release exists to end. It
     * sends its own, beside whatever the page sends.
     *
     * Only one thing to say, unlike the grid: `SaveReport::refused` is always
     * empty from this screen, because a checkbox has no third value for two
     * people to disagree about. `Assignment::apply()` carries the reasoning.
     */
    public function announce(SaveReport $report): void
    {
        if ($report->preserved === 0) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-warden::ui.relations.roles.concurrent.kept_title'))
            ->body(trans_choice('filament-warden::ui.relations.roles.concurrent.kept', $report->preserved))
            ->send();
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
