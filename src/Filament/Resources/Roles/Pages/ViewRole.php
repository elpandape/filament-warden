<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Grants\RoleHolders;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The resolved chain, for the form that offers the inheritance.
     *
     * Public because `RoleForm` draws it under its own select: inheriting from
     * two roles can bring five, and that figure is nowhere in the select.
     * Called rather than copied: two wordings of one fact drift apart.
     */
    public static function chain(Model $record): string
    {
        return self::hierarchy($record);
    }

    /**
     * The title, under the heading, because the heading is the code name.
     *
     * `recordTitleAttribute` is `name` — what code and `roles.protected` refer
     * to a role by, so it is what a breadcrumb has to say — and with no identity
     * card on this screen, the subheading is where Filament gives the title a
     * place. Null when there is none: warden lets a role have no title.
     */
    public function getSubheading(): ?string
    {
        $title = $this->getRecord()->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * The edit action needs nothing written by hand — the resource leaves
     * `canEdit()` alone, so the policy closes it on its own, and a protected role
     * opens with its form disabled rather than not opening at all.
     *
     * The delete action does, for the same reason as on the edit screen: its
     * authorization response never passes through `RoleResource::canDelete()`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->handOut(),
            EditAction::make(),
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
        ];
    }

    /**
     * The write itself, through the fluent API and in warden's own order.
     *
     * `until()` before `to()`: an assignment executes ON `to()`, and warden
     * throws rather than let a date be added to something already written —
     * exactly as `AssignsRoles::on()` is guarded. And `until(null)` rather than
     * skipping the call, because a role handed out again to somebody whose
     * earlier assignment lapsed would otherwise find the dead row and change
     * nothing: `firstOrCreate` finds it, and warden only moves the date when a
     * chain declared one.
     */
    private static function assign(Model $record, mixed $account, mixed $until): void
    {
        $holder = ViewPermission::accountFor($account);

        if (! $holder instanceof Model) {
            // Unreachable through the screen: a `Select` adds an `in:` rule over
            // the options it can label, so a key that names no row is refused
            // before the action runs. It stays because the guarantee is about
            // what gets WRITTEN, and that must not rest on how another package
            // derives a validation rule.
            return; // @codeCoverageIgnore
        }

        Warden::assign($record)
            ->until(is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null)
            ->to($holder);

        // The tally beside this action was read before the write and the page
        // redraws in the same request: without this it would come back saying
        // what it said a moment ago, which is the one thing a screen that just
        // handed a role out must not do.
        RoleHolders::forget($record);
    }

    private static function mayHandOut(Model $record): bool
    {
        $account = Filament::auth()->user();

        return $account instanceof Model && Access::granted($account, 'update', $record);
    }

    /**
     * What this role reaches, and who reaches it, as counts rather than lists.
     *
     * A count and a folded chain, never the chain itself: the number of hops is
     * NOT available — warden's `RoleClosure::for()` returns
     * `[restriction type, restriction id, end date]` and carries no depth — and
     * deriving one would mean walking the closure a second time, which is the
     * one thing this package does not do with a rule warden already owns.
     *
     * Two figures and not one, because they are different facts: what somebody
     * chose, and what the choice brought along. A single total hides which is
     * which, and only the first is a thing anybody can change from a screen.
     */
    private static function hierarchy(Model $record): string
    {
        $hierarchy = Hierarchy::of($record);
        $reaching = Hierarchy::reaching($record);

        if ($hierarchy->direct === [] && $reaching === []) {
            return (string) __('filament-warden::ui.resources.roles.hierarchy.none');
        }

        $clauses = [];

        // Two clauses and not one sentence with four numbers in it: a single
        // line cannot pluralise four things at once, and `(s)` is a fake plural
        // this suite refuses outright. Composed the way `savedBody()` composes
        // its own, which is the shape this package already had.
        if ($hierarchy->direct !== []) {
            $clauses[] = trans_choice('filament-warden::ui.resources.roles.hierarchy.inherits', count($hierarchy->direct), [
                'brought' => count($hierarchy->inherited),
                'total' => count($hierarchy->all()),
            ]);
        }

        $clauses[] = trans_choice('filament-warden::ui.resources.roles.hierarchy.reaching', count($reaching));

        return implode('. ', $clauses).'.';
    }

    /**
     * The way IN from the role.
     *
     * `update` on the role is what it asks for, and the choice is not arbitrary:
     * handing a role out is changing who holds it, which is the same power the
     * grid on the edit screen already needs. `create` would be a lie — nothing
     * is created — and `view` would let somebody who may only look hand out
     * every power the role has.
     *
     * The `visible()` is the only gate the button has:
     * `Page::getDefaultActionAuthorizationResponse()` maps Filament's own CRUD
     * actions to the policy and answers `null` for any other, which an action
     * reads as allowed. It is checked again inside the action, because
     * `visible()` decides whether the button exists and the server is what
     * decides whether the write happens.
     */
    private function handOut(): Action
    {
        return Action::make('handOut')
            ->label(__('filament-warden::ui.resources.roles.hand_out.label'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible(static fn (Model $record): bool => self::mayHandOut($record))
            ->schema([
                Select::make('account')
                    ->label(__('filament-warden::ui.resources.roles.hand_out.account'))
                    ->required()
                    ->searchable()
                    // The permission screen's own search, called rather than
                    // copied: it already resolves the account model through the
                    // panel's guard, drops the columns a `LIKE` cannot be pointed
                    // at, and escapes its wildcards WITH the clause that keeps
                    // that from turning into an empty search. A second copy
                    // would be a second one to keep portable across engines.
                    ->getSearchResultsUsing(static fn (string $search): array => ViewPermission::accounts($search))
                    ->getOptionLabelUsing(static fn (mixed $value): ?string => ViewPermission::accountLabel($value)),

                DatePicker::make('until')
                    ->label(__('filament-warden::ui.resources.roles.hand_out.until'))
                    ->helperText(__('filament-warden::ui.resources.roles.hand_out.until_help'))
                    // Today is not in the future, and warden reads the boundary
                    // exclusively: a row stops counting AT the instant it names,
                    // so a date of today would hand out something that ended
                    // before the notification was written.
                    ->after('today'),
            ])
            ->action(static function (Model $record, array $data): void {
                // The second of two, and the first is the `visible()` above.
                // Unreachable while that one is right: `mountAction()` and
                // `callMountedAction()` refuse a disabled action, and a hidden
                // one is disabled. What this holds is the day somebody removes
                // the `visible()` while editing this file, or reaches this
                // action from a screen that never had one.
                if (! self::mayHandOut($record)) {
                    return; // @codeCoverageIgnore
                }

                self::assign($record, $data['account'] ?? null, $data['until'] ?? null);
            });
    }
}
