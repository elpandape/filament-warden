<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas;

use ElPandaPe\FilamentWarden\Catalog\Abilities;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Provenance;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Morph;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The same questions the form asks, answered instead, in the sketch's own
 * layout: what the rule is on the left, and who it fell to beside it.
 *
 * Two stacks and not one column, because the two answer different questions and
 * only the left one grows. «How far it reaches» is about the RULE — a name, an
 * entity, and the condition read out as it will be evaluated — and the bench
 * that `ViewPermission` puts under it asks that same rule about one account.
 * The aside is about the STORE: how many hold it, and what put the row there.
 *
 * Who holds it arrives as counts and not as names: a role screen has a handful
 * of roles, and a permission can be held by every account in the installation.
 * The names are read in one place only — the moment somebody deletes it, and
 * the grants go with it.
 *
 * There is no identity card and the sketch has none: the name is the heading
 * Filament already draws, and the title is the subheading `ViewPermission` puts
 * under it.
 */
final class PermissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(4)
            ->components([
                // The sketch's `fi-aside`: two stacks, the wide one first. A
                // section spanning 2 followed by one spanning 1 would fill the
                // first row and then start the next one on the LEFT — which is
                // how the provenance card ends up under the rule instead of
                // under the holders. The group is what keeps a stack a stack.
                Group::make([
                    Section::make(__('filament-warden::ui.resources.permissions.sections.reach'))
                        ->icon(Heroicon::OutlinedFunnel)
                        ->columns(3)
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('filament-warden::ui.resources.permissions.fields.name'))
                                ->copyable(),

                            TextEntry::make('entity_type')
                                ->label(__('filament-warden::ui.resources.permissions.fields.entity'))
                                ->formatStateUsing(static fn (string $state): string => self::entity($state))
                                ->placeholder(__('filament-warden::ui.resources.permissions.entity.none')),

                            TextEntry::make('only_owned')
                                ->label(__('filament-warden::ui.resources.permissions.fields.only_owned'))
                                ->state(static fn (Model $record): string => (string) __(
                                    'filament-warden::ui.resources.permissions.holders.'
                                    .((bool) $record->getAttribute('only_owned') ? 'yes' : 'no'),
                                )),

                            TextEntry::make('reach')
                                ->label(__('filament-warden::ui.resources.permissions.columns.reach'))
                                ->badge()
                                ->state(static fn (Model $record): string => self::reach($record)),

                            // The rule under the three facts that frame it, at
                            // full width, because it is the only one of them
                            // that can run long — and it is read out with its
                            // brackets, the way warden will evaluate it.
                            TextEntry::make('rule')
                                ->label(__('filament-warden::ui.resources.permissions.fields.conditions'))
                                ->placeholder('—')
                                ->state(static fn (Model $record): ?string => self::rule($record))
                                ->helperText(static fn (Model $record): ?string => self::ruleHelp($record))
                                ->columnSpanFull(),
                        ]),
                ])->columnSpan(3),

                Group::make([
                    Section::make(__('filament-warden::ui.resources.permissions.sections.holders'))
                        ->icon(Heroicon::OutlinedUsers)
                        ->columns(2)
                        ->schema([
                            TextEntry::make('roles')
                                ->label(__('filament-warden::ui.resources.permissions.holders.roles'))
                                ->state(static fn (Model $record): int => Holders::of($record)->roleCount),

                            TextEntry::make('accounts')
                                ->label(__('filament-warden::ui.resources.permissions.holders.accounts'))
                                ->state(static fn (Model $record): int => Holders::of($record)->accountCount),

                            TextEntry::make('everyone')
                                ->label(__('filament-warden::ui.resources.permissions.holders.everyone'))
                                ->state(static fn (Model $record): string => (string) __(
                                    'filament-warden::ui.resources.permissions.holders.'
                                    .(Holders::of($record)->everyone ? 'yes' : 'no'),
                                )),

                            // A denial is a state and not an absence, so it is
                            // never folded into the tally of who holds it.
                            TextEntry::make('forbidden')
                                ->label(__('filament-warden::ui.resources.permissions.holders.forbidden'))
                                ->badge()
                                ->color(static fn (Model $record): string => Holders::of($record)->forbidden > 0 ? 'danger' : 'gray')
                                ->state(static fn (Model $record): int => Holders::of($record)->forbidden),

                            // A separate axis from the four above and not a
                            // fifth kind of holder: a grant that ends is held
                            // today by whoever holds it, and counted in
                            // whichever of the figures beside this one names
                            // them. What it says is when that stops being true
                            // without anybody doing anything.
                            TextEntry::make('ending')
                                ->label(__('filament-warden::ui.resources.permissions.holders.ending'))
                                ->badge()
                                ->color(static fn (Model $record): string => Holders::of($record)->ending > 0 ? 'info' : 'gray')
                                ->state(static fn (Model $record): int => Holders::of($record)->ending),

                            // The other half of the same fact, and the reason
                            // the four figures beside it can stay wide: they
                            // count what a delete destroys, and a lapsed grant
                            // is destroyed like any other. This is how many of
                            // them are already answering nothing.
                            TextEntry::make('lapsed')
                                ->label(__('filament-warden::ui.resources.permissions.holders.lapsed'))
                                ->badge()
                                ->color(static fn (Model $record): string => Holders::of($record)->lapsed > 0 ? 'warning' : 'gray')
                                ->state(static fn (Model $record): int => Holders::of($record)->lapsed),

                            TextEntry::make('holders_note')
                                ->hiddenLabel()
                                ->color('gray')
                                ->columnSpanFull()
                                ->state(__('filament-warden::ui.resources.permissions.holders.description').' '.__('filament-warden::ui.resources.permissions.holders.every_tenant')),
                        ]),

                    // Its own card rather than a badge among the identity
                    // fields, because it is the one thing on this screen that
                    // can go stale without anybody touching the row: a policy
                    // method renamed leaves the permission behind, matching
                    // nothing and saying so nowhere. The badge is still the
                    // badge — the sentence under it names what to go and open.
                    Section::make(__('filament-warden::ui.resources.permissions.columns.provenance'))
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->schema([
                            TextEntry::make('provenance')
                                ->hiddenLabel()
                                ->badge()
                                ->state(static fn (Model $record): string => (string) __(
                                    'filament-warden::ui.provenance.'.Provenance::of($record, self::catalog())->value,
                                )),

                            TextEntry::make('declared_by')
                                ->hiddenLabel()
                                ->state(static fn (Model $record): string => self::declaredBy($record)),
                        ]),
                ])->columnSpan(1),
            ]);
    }

    /**
     * Which policy method put this row here, or what it means that none did.
     *
     * The catalogue is not asked: it answers what a PANEL declares, and this
     * question is about a class — a permission derived from a policy that no
     * panel exposes is still declared by that policy, and calling it undeclared
     * because no screen shows it would be a wrong reason for a true badge
     * (§6.34). The badge beside it is the catalogue's answer and stays.
     */
    private static function declaredBy(Model $record): string
    {
        $type = $record->getAttribute('entity_type');
        $name = $record->getAttribute('name');

        $model = is_string($type) && $type !== '*' ? Morph::model($type) : null;

        $method = $model !== null && is_string($name)
            ? Abilities::declaredBy($model, $name)
            : null;

        return $method === null
            ? (string) __('filament-warden::ui.resources.permissions.provenance.undeclared')
            : (string) __('filament-warden::ui.resources.permissions.provenance.declared_by', ['method' => $method]);
    }

    /**
     * The stored rule, read out as it will be evaluated — brackets included.
     */
    private static function rule(Model $record): ?string
    {
        $narrowing = Narrowing::of($record);

        if ($narrowing->rules === []) {
            return null;
        }

        return $narrowing->preview(
            (string) __('filament-warden::ui.conditions.authority'),
            (string) __('filament-warden::ui.conditions.and'),
            (string) __('filament-warden::ui.conditions.or'),
        );
    }

    /**
     * Why a rule that reads as generous can still answer nothing.
     *
     * Only under a rule, and that is the point: a condition needs a record in
     * front of it, so asked about the CLASS — which is what a role grid asks —
     * `passesConstraints()` returns before evaluating a single clause. The cell
     * abstains, and an abstention reads exactly like a rule that is not there.
     * Somebody reading this screen to find out why a grant "does nothing" is
     * reading it for this sentence.
     */
    private static function ruleHelp(Model $record): ?string
    {
        return self::rule($record) === null
            ? null
            : (string) __('filament-warden::ui.resources.permissions.provenance.rule_help');
    }

    /**
     * The same word the listing badges, worked out the same way. Both screens
     * change together or they disagree about one row: a rule pinned to a record
     * answers no class check, and no shape of the reach map describes it.
     */
    private static function reach(Model $record): string
    {
        if ($record->getAttribute('entity_id') !== null) {
            return (string) __('filament-warden::ui.resources.permissions.entity.record');
        }

        return (string) __('filament-warden::ui.reach.'.Narrowing::of($record)->shape->value);
    }

    private static function catalog(): Catalog
    {
        return Catalog::union(array_values(Filament::getPanels()));
    }

    /**
     * A null entity never reaches this: Filament draws the placeholder instead
     * of formatting, so a branch for it here would be a branch nothing runs.
     */
    private static function entity(string $state): string
    {
        return $state === '*'
            ? (string) __('filament-warden::ui.resources.permissions.entity.any')
            : Str::headline(Str::plural(class_basename(Str::afterLast($state, '.'))));
    }
}
