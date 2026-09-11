<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Infolists\PermissionGridEntry;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Grants\RoleHolders;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Config as WardenConfig;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use WeakMap;

/**
 * What the role is, in the shape the approved sketch draws it.
 *
 * Two cards side by side above the grid — what this role reaches through other
 * roles, and who reaches it — and then the grid at full width. The order is the
 * sketch's and it is not arbitrary: the grid is the tallest thing on the page,
 * so anything under it is under a scroll.
 *
 * There is no identity card, and the sketch has none either: the name is the
 * heading Filament already draws and the title is the subheading `ViewRole`
 * puts under it. A card repeating the two would be a third place to read them.
 *
 * The counts are counts and never a list, for the reason the permission screen
 * already gives: a role can be held by every account in the installation, so a
 * handful of names out of a thousand is decoration. The one place this package
 * names holders is the delete modal, where the names are what somebody is about
 * to destroy.
 */
final class RoleInfolist
{
    /** @var WeakMap<Model, array{inherits: string, inherited_by: string, direct: string, reaching: string}>|null */
    private static ?WeakMap $memo = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(4)
            ->components([
                Section::make(__('filament-warden::ui.resources.roles.sections.hierarchy'))
                    ->icon(Heroicon::OutlinedLink)
                    ->description(__('filament-warden::ui.resources.roles.hierarchy.description'))
                    ->columnSpan(3)
                    // Off out of the box, and the section goes with it rather
                    // than drawing two empty rows: with nesting off a role→role
                    // edge grants nothing, so a card about them would be a card
                    // about nothing.
                    ->visible(static fn (): bool => WardenConfig::nestedRoles())
                    ->extraAttributes(['style' => 'block-size: 100%'])
                    ->schema([
                        TextEntry::make('inherits')
                            ->label(__('filament-warden::ui.resources.roles.sections.inherits'))
                            ->state(static fn (Model $record): string => self::chain($record)['inherits'])
                            ->helperText(static fn (Model $record): ?string => self::names(self::chain($record)['direct'])),

                        TextEntry::make('inherited_by')
                            ->label(__('filament-warden::ui.resources.roles.hierarchy.inherited_by'))
                            ->state(static fn (Model $record): string => self::chain($record)['inherited_by'])
                            ->helperText(static fn (Model $record): ?string => self::names(self::chain($record)['reaching'])),
                    ]),

                Section::make(__('filament-warden::ui.resources.roles.sections.holders'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->description(__('filament-warden::ui.resources.roles.holders.description'))
                    // Fill the row when hierarchy has no section to occupy the main track.
                    ->columnSpan(static fn (): int => WardenConfig::nestedRoles() ? 1 : 4)
                    ->extraAttributes(['style' => 'block-size: 100%'])
                    ->columns(2)
                    ->schema([
                        // Counted and never named, which is the doctrine the
                        // permission screen already had written down and the
                        // same reason: a role can be held by every account in
                        // the installation, so ten names out of a thousand do
                        // not inform, they decorate. What the figure is FOR is
                        // the grid underneath — it says how many people the
                        // next save reaches.
                        TextEntry::make('holders_held')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total > 0)
                            ->state(static fn (Model $record): string => (string) __(
                                'filament-warden::ui.resources.roles.holders.held',
                                ['count' => RoleHolders::of($record)->total],
                            )),

                        // Four zeros are not an empty state, they are four
                        // zeros. A role nobody holds is a common and useful
                        // thing to be told in words.
                        TextEntry::make('holders_nobody')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total === 0)
                            ->state(__('filament-warden::ui.resources.roles.holders.nobody')),

                        // The three words `RolesRelationManager` already says
                        // about one account's row, counted here over the role's.
                        // Same keys, so the two screens cannot drift apart.
                        TextEntry::make('holders_here')
                            ->label(__('filament-warden::ui.relations.roles.held.here'))
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total > 0)
                            ->state(static fn (Model $record): int => RoleHolders::of($record)->here),

                        TextEntry::make('holders_restricted')
                            ->label(__('filament-warden::ui.relations.roles.held.restricted'))
                            ->badge()
                            ->color(static fn (Model $record): string => RoleHolders::of($record)->restricted > 0 ? 'warning' : 'gray')
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total > 0)
                            ->state(static fn (Model $record): int => RoleHolders::of($record)->restricted),

                        // Grey whatever it says, unlike the two beside it: a row
                        // written at another scope is not a warning, it is a
                        // fact this tenant cannot act on.
                        TextEntry::make('holders_elsewhere')
                            ->label(__('filament-warden::ui.relations.roles.held.elsewhere'))
                            ->badge()
                            ->color('gray')
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total > 0)
                            ->state(static fn (Model $record): int => RoleHolders::of($record)->elsewhere),

                        // Not a fourth kind of holder — see `RoleHolders`.
                        TextEntry::make('holders_ending')
                            ->label(__('filament-warden::ui.resources.roles.holders.ending'))
                            ->badge()
                            ->color(static fn (Model $record): string => RoleHolders::of($record)->ending > 0 ? 'info' : 'gray')
                            ->visible(static fn (Model $record): bool => RoleHolders::of($record)->total > 0)
                            ->state(static fn (Model $record): int => RoleHolders::of($record)->ending),
                    ]),

                Section::make(__('filament-warden::ui.grid.label'))
                    ->description(__('filament-warden::ui.grid.read_description'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columnSpanFull()
                    ->schema([
                        PermissionGridEntry::make('permissions')
                            ->hiddenLabel()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Both halves of the hierarchy, worked out once per record.
     *
     * Memoised because eight closures on this screen ask for it and each answer
     * costs a closure walk plus a read to name what it found; and on the
     * instance rather than on its key, for the reason §6.35 measured about
     * `spl_object_hash()`. Nothing that WRITES an edge passes through this
     * class, so there is no answer here that a save could leave stale —
     * `Hierarchy::apply()` is the form's, and the form draws its own sentence
     * through `ViewRole::chain()`.
     *
     * The same two translated lines the form's own summary uses, arranged as
     * two labelled rows instead of one sentence: the sketch has a row per
     * question, and the wording that answers each is the one this package
     * already settled.
     *
     * @return array{inherits: string, inherited_by: string, direct: string, reaching: string}
     */
    private static function chain(Model $record): array
    {
        self::$memo ??= new WeakMap();

        return self::$memo[$record] ??= self::walk($record);
    }

    /**
     * @return array{inherits: string, inherited_by: string, direct: string, reaching: string}
     */
    private static function walk(Model $record): array
    {
        $hierarchy = Hierarchy::of($record);
        $reaching = Hierarchy::reaching($record);

        return [
            // Both rows answer even at zero, and both lines carry a `{0}` arm
            // for it. A dash would be the same "nothing" the placeholder of an
            // unfilled column means, and on this screen zero is a fact somebody
            // came to read — a role nothing inherits is a role whose grid
            // reaches only whoever holds it.
            'inherits' => trans_choice('filament-warden::ui.resources.roles.hierarchy.inherits', count($hierarchy->direct), [
                'brought' => count($hierarchy->inherited),
                'total' => count($hierarchy->all()),
            ]),
            'inherited_by' => trans_choice('filament-warden::ui.resources.roles.hierarchy.reaching', count($reaching)),
            'direct' => self::label($hierarchy->direct),
            'reaching' => self::label($reaching),
        ];
    }

    /**
     * The keys a closure hands back, named, in one read.
     *
     * `Holders::label()` and not the `name` column: it is the same wording every
     * other screen puts on a role, and it goes through `getAttributes()` rather
     * than `getAttribute()`, which is what keeps a swapped-in role model from
     * throwing under `Model::shouldBeStrict()`.
     *
     * @param  list<int|string>  $keys
     */
    private static function label(array $keys): string
    {
        if ($keys === []) {
            return '';
        }

        $labels = [];

        foreach (Context::resolve()->roleClass()::query()->whereKey($keys)->get() as $role) {
            $labels[] = Holders::label($role);
        }

        return implode(', ', $labels);
    }

    private static function names(string $labels): ?string
    {
        return $labels === '' ? null : $labels;
    }
}
