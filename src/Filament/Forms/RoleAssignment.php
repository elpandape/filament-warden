<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms;

use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Grants\SaveReport;
use ElPandaPe\Warden\Context;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Handing roles to an account, from the account's own screen.
 *
 * A package cannot put a relation manager on a resource it does not own —
 * `Resource::getRelations()` is a concrete static nothing else can write to —
 * so this is a field the application adds to its own form, and
 * `RolesRelationManager` is the same job as a tab for an application that
 * registers it. Neither uses Filament's `AttachAction` or `DetachAction`:
 * `RelationManager::getDefaultActionAuthorizationResponse()` gates both only
 * on `isReadOnly()`, which is false on any edit page, and checks no policy.
 *
 * Nor `->relationship()`, which saves through `sync()`: the whole write goes
 * through `Assignment` and warden's fluent API, for the reasons on that class.
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

                // Reachable for the rest of the request, so a page this package
                // does not own can say more than this field's one notification
                // — the same recipe the grid offers, and it is offered from
                // both screens or the README cannot describe it as one thing.
                // Thinner here on purpose: every list of cells is always empty
                // from this screen, and no stance exists to split `written` by,
                // so what a caller gets is the two counts.
                app()->instance(SaveReport::class, $report);

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
     * A `CheckboxList` has ONE state slot and it holds the set, so the copy sits
     * in the page's own state array as a sibling key: it survives the mount, a
     * click and the save, and `Schema::getState()` does not return it, so it
     * can never reach `$record->update()`.
     *
     * That array belongs to the application, so the key is namespaced and this
     * is the only place that writes it. A page with nowhere to put it gets no
     * baseline at all — see `holder()`.
     *
     * @param  list<int|string>  $held
     */
    public function fillFrom(array $held): void
    {
        $this->state($held);

        $holder = $this->holder();

        if ($holder !== null) {
            $livewire = $this->getLivewire();

            $bag = data_get($livewire, $holder.'.'.self::BASELINE);
            $bag = is_array($bag) ? $bag : [];
            $bag[$this->getStatePath() ?? ''] = $held;

            data_set($livewire, $holder.'.'.self::BASELINE, $bag);
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

        $bag = data_get($this->getLivewire(), $holder.'.'.self::BASELINE);
        $baseline = is_array($bag) ? ($bag[$this->getStatePath() ?? ''] ?? null) : null;

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
     * This field is one line inside a form the application wrote, so there is
     * no "Saved" notification of ours to replace — it sends its own, beside
     * whatever the page sends.
     *
     * Only one thing to say, unlike the grid: `SaveReport::refused` is always
     * empty from this screen, because a checkbox has no third value for two
     * people to disagree about. `Assignment::apply()` carries the reasoning.
     *
     * Sent through `afterCommit` and not straight away, for the same reason as
     * `PermissionGrid::announce()`: this runs inside `getState()`, before the
     * record is updated and inside whatever transaction the page opened.
     */
    public function announce(SaveReport $report): void
    {
        if ($report->preserved === 0) {
            return;
        }

        $notification = Notification::make()
            ->success()
            ->title(__('filament-warden::ui.relations.roles.concurrent.kept_title'))
            ->body(trans_choice('filament-warden::ui.relations.roles.concurrent.kept', $report->preserved));

        // Reached through a model, as in `PermissionGrid::sendAfterCommit()`,
        // whose docblock says why.
        (new (Context::resolve()->grantClass()))->getConnection()->afterCommit(
            static function () use ($notification): void {
                $notification->send();
            },
        );
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
     * The one bag the copies sit in, or null when there is none.
     *
     * The state path of this field's ROOT container, which is the only
     * candidate that is both a real state bag and outside every repeating item,
     * so what goes there is pruned away exactly like the field's own state.
     * Each of the three obvious alternatives is wrong somewhere, and each has a
     * test:
     *
     * - a property named `data` — Filament mounts schemas under other roots
     *   too, so the field is unprotected wherever the name differs;
     * - the ROOT of the state path — an action modal's is `mountedActions`, and
     *   a string key in it breaks `array_key_last()`, `array_pop()` and
     *   `getMountedActionSchemaName()`;
     * - the immediate CONTAINER — a repeating one validates on itself, so
     *   Laravel returns the whole item as validated data and the copy rides into
     *   `$record->update()`.
     *
     * Keyed by each field's full state path, so two rows of a repeater get two
     * copies and two of these fields on one form do not collide.
     */
    private function holder(): ?string
    {
        $root = $this->getRootContainer()->getStatePath();

        return $root === '' ? null : $root;
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
