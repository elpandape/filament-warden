<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Config as WardenConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who the role is, and what it can do — in that order, and in two sections,
 * because they are two questions and the second one is the whole screen.
 *
 * On a protected role the name and the grid are shown and not editable, and the
 * title is left alone. The rule: what changes behaviour is protected, what only
 * changes the wording is not. The name is the identifier — `roles.protected`
 * matches by it, so renaming it unprotects the role on the spot — and the grid is
 * its powers. The title is a label nothing resolves by.
 */
final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Two tracks above and the whole grid below: identity and
            // inheritance are two short questions that fit side by side, while
            // the matrix needs all the width there is.
            //
            // Identity takes both tracks when inheritance is absent: half a card
            // beside an empty half is worse than a whole one, and with
            // `warden.roles.nested` off — the default — there is nothing to put
            // beside it.
            ->columns(2)
            ->components([
                Section::make(__('filament-warden::ui.resources.roles.sections.identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    // Both cards in the row reach the same bottom. Filament
                    // already stretches each one's `.fi-sc-component` WRAPPER,
                    // but the card inside keeps its content height, so the one
                    // with less text floats above a gap.
                    //
                    // An inline style and not a class: the host application
                    // compiles the panel's CSS and its build does not scan this
                    // package's views, so a utility only the plugin uses may
                    // never be compiled. And a rule in the package's own
                    // stylesheet would have to target `.fi-sc-section`, which is
                    // Filament's and is everywhere in the application.
                    ->extraAttributes(['style' => 'block-size: 100%'])
                    ->columnSpan(static fn (): int => WardenConfig::nestedRoles() ? 1 : 2)
                    // Stacked at half width and side by side at full width: two
                    // text inputs next to each other in half a card are two
                    // boxes too narrow for what they hold.
                    ->columns(static fn (): int => WardenConfig::nestedRoles() ? 1 : 2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament-warden::ui.resources.roles.fields.name'))
                            ->helperText(__('filament-warden::ui.resources.roles.fields.name_help'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && RoleResource::isProtected($record))
                            ->notIn(static fn (?Model $record): array => self::otherProtectedNames($record))
                            ->validationMessages(['not_in' => (string) __('filament-warden::ui.resources.roles.fields.name_protected')]),

                        TextInput::make('title')
                            ->label(__('filament-warden::ui.resources.roles.fields.title'))
                            ->helperText(__('filament-warden::ui.resources.roles.fields.title_help'))
                            ->maxLength(255),
                    ]),

                Section::make(__('filament-warden::ui.resources.roles.sections.inherits'))
                    ->description(__('filament-warden::ui.resources.roles.sections.inherits_help'))
                    ->icon(Heroicon::OutlinedLink)
                    ->extraAttributes(['style' => 'block-size: 100%'])
                    ->columnSpan(1)
                    ->visible(static fn (): bool => WardenConfig::nestedRoles())
                    ->schema([
                        Select::make('inherits')
                            ->label(__('filament-warden::ui.resources.roles.fields.inherits'))
                            ->helperText(static fn (): string => (string) __(
                                'filament-warden::ui.resources.roles.fields.inherits_help',
                                ['depth' => WardenConfig::roleMaxDepth()],
                            ))
                            ->multiple()
                            // In the SERVER, and not a loaded list: an
                            // installation with two hundred roles would ship
                            // every one of them into every render of this form
                            // to fill a control most saves never touch.
                            ->searchable()
                            ->getSearchResultsUsing(static fn (string $search, ?Model $record): array => self::offerable($record, $search))
                            ->getOptionLabelsUsing(static fn (array $values): array => self::named($values))
                            ->dehydrated(false)
                            ->afterStateHydrated(static function (Select $component, ?Model $record): void {
                                $component->state($record instanceof Model ? Hierarchy::of($record)->direct : []);
                            })
                            // Protected the same way the grid is, and for the
                            // same reason: inheriting is how a role gets powers,
                            // so it is the grid by another door.
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && RoleResource::isProtected($record)),

                        TextEntry::make('chain')
                            ->hiddenLabel()
                            ->visible(static fn (?Model $record): bool => $record instanceof Model)
                            ->state(static fn (?Model $record): string => $record instanceof Model
                                ? ViewRole::chain($record)
                                : ''),
                    ]),

                Section::make(__('filament-warden::ui.grid.label'))
                    ->description(__('filament-warden::ui.grid.description'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columnSpanFull()
                    ->schema([
                        // The grid can only ever take power away from a protected
                        // role: it holds the wildcard, which is not a cell, so
                        // nothing here can grant it more — only cut an explicit
                        // hole in what it already has.
                        PermissionGrid::make('permissions')
                            ->hiddenLabel()
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && RoleResource::isProtected($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The roles this one may be given, searched in the store.
     *
     * What may NOT be offered is `Hierarchy::barredFor()`, which is where that
     * rule lives so it can be asked directly — a search closure inside a form is
     * reachable from a screen and from nowhere a case can stand.
     *
     * @return array<int|string, string>
     */
    private static function offerable(?Model $record, string $search): array
    {
        $context = Context::resolve();

        $barred = $record instanceof Model ? Hierarchy::barredFor($record) : [];

        $roles = $context->roleClass()::query()
            ->when($barred !== [], static fn (Builder $query): Builder => $query->whereKeyNot($barred))
            // `%` and `_` are wildcards, so an unescaped `%` would page through
            // the role table rather than search it; escaped WITHOUT the clause,
            // SQLite, which has no default escape character, would match
            // nothing. `!` rather than a backslash, for the engine-by-engine
            // reasons on `ViewPermission::accounts()`.
            ->where(static function (Builder $query) use ($search): void {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

                $query->whereRaw("name like ? escape '!'", [$like])
                    ->orWhereRaw("title like ? escape '!'", [$like]);
            })
            ->limit(50)
            ->get();

        $options = [];

        foreach ($roles as $role) {
            $key = $role->getKey();

            if (is_int($key) || is_string($key)) {
                $options[$key] = Holders::label($role);
            }
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<int|string, string>
     */
    private static function named(array $values): array
    {
        $keys = array_values(array_filter($values, static fn (mixed $one): bool => is_int($one) || is_string($one)));

        if ($keys === []) {
            return [];
        }

        $named = [];

        foreach (Context::resolve()->roleClass()::query()->whereKey($keys)->get() as $role) {
            $key = $role->getKey();

            if (is_int($key) || is_string($key)) {
                $named[$key] = Holders::label($role);
            }
        }

        return $named;
    }

    /**
     * The protected names this form refuses: every one but the record's own.
     *
     * `roles.protected` matches by name, so the list is a door that locks behind
     * whoever walks onto it — and nothing asked on the way IN, because the name
     * field is only disabled for a role that is already protected and the create
     * screen has no record to ask about. Both screens share this schema, so one
     * rule closes both. Renaming a protected role OFF the list is the opposite
     * movement and is held in `EditRole`.
     *
     * The record's own name is exempt, as `unique(ignoreRecord: true)` is: a
     * disabled field is still VALIDATED, so refusing it would stop the role
     * saving the title it is still allowed to change. Left as the only entry it
     * compiles to `Rule::notIn([])` — `not_in:` with one null parameter, a rule
     * with no opinion rather than an error.
     *
     * The sentence is wired on `not_in`, the string rule the `NotIn` object is
     * cast to, and carries no placeholder: the list is unbounded, and in the
     * empty case above the rule passes and the sentence never renders.
     *
     * @return list<string>
     */
    private static function otherProtectedNames(?Model $record): array
    {
        $protected = Config::get('roles.protected');
        $own = $record?->getAttribute('name');

        /** @var list<string> $names */
        $names = array_values(array_filter(
            is_array($protected) ? $protected : [],
            static fn (mixed $name): bool => is_string($name) && $name !== $own,
        ));

        return $names;
    }
}
