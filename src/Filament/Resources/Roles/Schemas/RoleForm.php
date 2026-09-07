<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Config as WardenConfig;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
            ->columns(1)
            ->components([
                Section::make(__('filament-warden::ui.resources.roles.sections.identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
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
                    ]),

                Section::make(__('filament-warden::ui.grid.label'))
                    ->description(__('filament-warden::ui.grid.description'))
                    ->icon(Heroicon::OutlinedKey)
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
            // `%` and `_` are wildcards, so an unescaped `%` here would page
            // through the role table rather than search it — and escaping them
            // WITHOUT an `escape` clause is worse than not escaping at all:
            // SQLite has no default escape character, so the search goes from
            // too wide to permanently empty. `!` and not a backslash, for the
            // reason `ViewPermission` measured on three engines: `escape '\'`
            // is a syntax error on MySQL and doubling it breaks the other two.
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
     * The sentence is wired on `not_in`, the snake-cased basename of the rule
     * object, and carries no placeholder: the list is unbounded, and in the
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
