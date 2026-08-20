<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Filament\Forms\ConditionBuilder;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * What a permission is, and how far it reaches — in that order.
 *
 * Every field says why it is closed when it is closed. A screen that greys a
 * control out and explains nothing is a screen that gets worked around.
 */
class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('filament-warden::ui.resources.permissions.sections.identity'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament-warden::ui.resources.permissions.fields.name'))
                            ->helperText(static fn (?Model $record): string => self::nameHelp($record))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && ! PermissionResource::mayEditName($record))
                            // There is no unique index on this table, so `unique()`
                            // on one column would not describe the row: a permission
                            // is the tuple, and two identical ones are creatable.
                            ->rule(static fn (?Model $record): callable => static function (string $attribute, mixed $value, callable $fail) use ($record): void {
                                if (self::exists($value, $record)) {
                                    $fail(__('filament-warden::ui.resources.permissions.fields.taken'));
                                }
                            }),

                        TextInput::make('title')
                            ->label(__('filament-warden::ui.resources.permissions.fields.title'))
                            ->helperText(__('filament-warden::ui.resources.permissions.fields.title_help'))
                            ->placeholder(static fn (Get $get): string => self::generated($get))
                            ->maxLength(255),

                        Select::make('entity_type')
                            ->label(__('filament-warden::ui.resources.permissions.fields.entity'))
                            ->helperText(__('filament-warden::ui.resources.permissions.fields.entity_help'))
                            ->options(static fn (?Model $record): array => self::entities($record))
                            ->live()
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && ! PermissionResource::mayEditName($record))
                            // The conditions named columns of another table. Kept,
                            // they would be a rule that cannot be true.
                            ->afterStateUpdated(static fn (callable $set): mixed => $set('options', ['mode' => 'all', 'rules' => []]))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('filament-warden::ui.resources.permissions.sections.reach'))
                    ->icon(Heroicon::OutlinedFunnel)
                    ->description(static fn (?Model $record): ?string => self::sharedWarning($record))
                    ->schema([
                        Toggle::make('only_owned')
                            ->label(__('filament-warden::ui.resources.permissions.fields.only_owned'))
                            ->helperText(static fn (Get $get): string => self::ownershipHelp($get))
                            ->disabled(static fn (Get $get, ?Model $record): bool => ! self::ownable($get)
                                || ($record instanceof Model && ! PermissionResource::mayEditOwnership($record))),

                        ConditionBuilder::make('options')
                            ->label(__('filament-warden::ui.resources.permissions.fields.conditions'))
                            ->helperText(static fn (Get $get): string => self::conditionsHelp($get))
                            ->entity(static fn (Get $get): ?string => self::model($get))
                            ->disabled(static fn (?Model $record): bool => $record instanceof Model && ! PermissionResource::mayEditConditions($record))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Every entity the panel knows about, plus warden's wildcard and the loose
     * permission that points at nothing.
     *
     * And the row's own entity, always — even when this panel does not show it.
     * The catalogue is per panel and the permissions table is not: without this,
     * a permission belonging to another panel could not be opened at all,
     * because the select would refuse the value it was drawn with.
     *
     * @return array<string, string>
     */
    public static function entities(?Model $record = null): array
    {
        // Nullable in the signature and never null in fact.
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();

        $options = ['*' => (string) __('filament-warden::ui.resources.permissions.entity.any')];

        foreach (Catalog::for($panel)->entries as $entry) {
            if ($entry->entityType !== null && $entry->model !== null) {
                $options[$entry->entityType] = Str::headline(Str::plural(class_basename($entry->model)));
            }
        }

        $own = $record?->getAttribute('entity_type');

        if (is_string($own) && $own !== '' && ! array_key_exists($own, $options)) {
            $options[$own] = Str::headline(Str::plural(class_basename(Str::afterLast($own, '.'))));
        }

        return $options;
    }

    /**
     * A derived permission's name is written by the policy method that declares
     * it. Changing it does not break anything loudly — it disconnects the row
     * from the code that asks for it, and nothing says so afterwards.
     */
    private static function nameHelp(?Model $record): string
    {
        return $record instanceof Model && $record->getAttribute('entity_type') !== null
            ? (string) __('filament-warden::ui.resources.permissions.fields.name_help_derived')
            : (string) __('filament-warden::ui.resources.permissions.fields.name_help_loose');
    }

    /**
     * What warden would call it. The title is generated in the `creating` hook
     * and only when it is null, so it never catches up with a rename on its own.
     */
    private static function generated(Get $get): string
    {
        $name = $get('name');
        $type = $get('entity_type');

        if (! is_string($name) || $name === '') {
            return '';
        }

        // A name this package minted has a title only this package can read back.
        return PermissionName::title($name)
            ?? PermissionTitle::generate($name, is_string($type) ? $type : null, null, (bool) $get('only_owned'));
    }

    /**
     * @return class-string<Model>|null
     */
    private static function model(Get $get): ?string
    {
        $type = $get('entity_type');

        if (! is_string($type) || $type === '*') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($class, Model::class) ? $class : null;
    }

    private static function ownable(Get $get): bool
    {
        $model = self::model($get);

        return $model !== null && Ownership::of($model)->available;
    }

    private static function ownershipHelp(Get $get): string
    {
        $model = self::model($get);

        if ($model === null) {
            return (string) __('filament-warden::ui.resources.permissions.fields.only_owned_no_model');
        }

        $ownership = Ownership::of($model);

        return $ownership->available
            ? (string) __('filament-warden::ui.resources.permissions.fields.only_owned_help')
            : (string) __('filament-warden::ui.conditions.no_ownership', [
                'table' => new $model()->getTable(),
                'column' => $ownership->column ?? '',
            ]);
    }

    private static function conditionsHelp(Get $get): string
    {
        return self::model($get) === null
            ? (string) __('filament-warden::ui.conditions.no_model')
            : (string) __('filament-warden::ui.conditions.warning');
    }

    /**
     * A permission carrying conditions is a shared row: two roles that hold it
     * point at the same one, and editing it here moves the rule for both. That
     * is right for a catalogue — the row IS the rule — and it has to be said.
     */
    private static function sharedWarning(?Model $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        $holders = Holders::of($record);

        return $holders->total() > 1
            ? (string) __('filament-warden::ui.resources.permissions.fields.conditions_shared', ['count' => $holders->total()])
            : null;
    }

    /**
     * Whether the tuple is already in the catalogue. A permission is
     * (action, entity, ownership), not a name.
     */
    private static function exists(mixed $name, ?Model $record): bool
    {
        $class = Context::resolve()->permissionClass();

        return is_string($name) && $class::query()
            ->withoutGlobalScopes()
            ->where('name', $name)
            ->when($record instanceof Model, static fn (mixed $query): mixed => $query->whereKeyNot($record?->getKey()))
            ->whereNull('options')
            ->exists();
    }
}
