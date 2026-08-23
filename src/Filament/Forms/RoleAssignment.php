<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
     * is the only place that writes it. A page with nowhere to put it — see
     * `holder()` — gets no baseline, and the save behaves as it did before there
     * was one.
     *
     * @param  list<int|string>  $held
     */
    public function fillFrom(array $held): void
    {
        $this->state($held);

        $holder = $this->holder();

        if ($holder !== null) {
            $livewire = $this->getLivewire();

            data_set($livewire, $holder.'.'.self::BASELINE, $held);
        }
    }

    /**
     * @return list<int|string>|null
     */
    public function baseline(): ?array
    {
        $holder = $this->holder();

        if ($holder === null) {
            return null;
        }

        $baseline = data_get($this->getLivewire(), $holder.'.'.self::BASELINE);

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
     * The state bag the copy sits in, or null when there is none to sit in.
     *
     * It is this field's own CONTAINER path — everything before the last
     * segment — and both halves of that matter.
     *
     * Not a property called `data`: Filament's own pages call it that, but
     * Filament itself mounts schemas under eight different roots (`data`,
     * `filters`, `tableFilters`, `deferredFilters`, `settings`, `columnMap`,
     * and more), so keying off the name left this field silently unprotected
     * wherever that name was something else — the screen looking fixed while
     * the defect stayed live on it.
     *
     * And not the ROOT of that path either, which was the first correction and
     * was worse: a field inside an action modal has the path
     * `mountedActions.{i}.data.roles`, whose root is `mountedActions` — a public
     * array Filament reads and writes, and still the wrong one. A string key in
     * it breaks `array_key_last()` and `array_pop()`, and
     * `getMountedActionSchemaName()` then resolves a schema that does not exist.
     * The container path puts the copy inside the action's own state bag, which
     * is where the field's own state already is.
     *
     * The bag has to be there already. A schema whose state path names something
     * the component does not have would otherwise get a dynamic property created
     * here, gone by the next request — the same `PropertyNotFoundException` this
     * method exists to avoid, one layer over.
     */
    private function holder(): ?string
    {
        $path = $this->getStatePath() ?? '';

        if (! str_contains($path, '.')) {
            return null;
        }

        $holder = Str::beforeLast($path, '.');

        return is_array(data_get($this->getLivewire(), $holder)) ? $holder : null;
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
