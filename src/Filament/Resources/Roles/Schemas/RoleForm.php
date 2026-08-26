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
