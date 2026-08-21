<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
class RoleForm
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
                            ->notIn(static fn (?Model $record): array => self::otherProtectedNames($record)),

                        TextInput::make('title')
                            ->label(__('filament-warden::ui.resources.roles.fields.title'))
                            ->helperText(__('filament-warden::ui.resources.roles.fields.title_help'))
                            ->maxLength(255),
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
     * The protected names this form refuses: every one but the record's own.
     *
     * `roles.protected` matches by name, so the list is a door that locks behind
     * whoever walks onto it. A role holding one of those names can no longer be
     * renamed, deleted, or have its grid touched — and nothing was asking on the
     * way in: the name field is only disabled for a role that is ALREADY
     * protected, and the create screen has no record to ask about at all. Both
     * screens share this schema, and validation runs on the server, so one rule
     * closes both doors. Going the other way — a protected role renaming its way
     * OFF the list — is the opposite movement and is held in `EditRole`.
     *
     * The record's own name is exempt for the same reason `unique()` takes
     * `ignoreRecord: true`. A protected role has this field disabled, and a
     * disabled field is still VALIDATED — `isValidatedWhenNotDehydrated` defaults
     * to true — so refusing its own name outright would stop it saving the title
     * it is still allowed to change. When that name is the only one listed the
     * result is `Rule::notIn([])`, which compiles to `not_in:` and parses through
     * `str_getcsv('')` to a single null parameter: a rule with no opinion, not an
     * error.
     *
     * The sentence a person reads is the framework's `validation.not_in`. One of
     * this package's own, naming the list and saying what holding that name
     * costs, would be a new translation key — a MINOR — and waits for 1.1.0.
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
