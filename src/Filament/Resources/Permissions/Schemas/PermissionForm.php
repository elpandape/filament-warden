<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
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
                            //
                            // `$get` and `$record` are injected by PARAMETER NAME,
                            // not by type: `Component::resolveDefault…ByName()` is
                            // consulted first and answers both, while the by-type
                            // path hands back the record for anything typed as a
                            // model and would leave `Get` to the container. Rename
                            // either and this closure stops resolving.
                            ->rule(static fn (?Model $record, Get $get): callable => static function (string $attribute, mixed $value, callable $fail) use ($record, $get): void {
                                if (self::exists($value, $record, $get)) {
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
                            // The conditions named columns of another table, and the
                            // ownership was resolved against a column this entity may
                            // not have. Kept, either would be a rule that cannot be
                            // true — and an `only_owned` over an attribute that is not
                            // a column does not fail closed: it emits invalid SQL and
                            // throws when the query runs.
                            ->afterStateUpdated(static function (callable $set): void {
                                $set('options', ['mode' => 'all', 'rules' => []]);
                                $set('only_owned', false);
                            })
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
                            ->helperText(static fn (Get $get, ?Model $record): string => self::conditionsHelp($get, $record))
                            ->entity(static fn (Get $get): ?string => self::model($get))
                            // Disabled is what keeps `options` out of the saved
                            // data at all: Filament's `disabled()` also calls
                            // `saved(false)`, and a component that is not
                            // dehydrated is forgotten rather than written.
                            ->disabled(static fn (?Model $record): bool => ($record instanceof Model && ! PermissionResource::mayEditConditions($record))
                                || ! self::conditionsWritable($record))
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
     * The entity the form is holding right now: what is about to be saved, not
     * what the row was drawn from.
     */
    private static function entityType(Get $get): ?string
    {
        $type = $get('entity_type');

        return is_string($type) ? $type : null;
    }

    /**
     * @return class-string<Model>|null
     */
    private static function model(Get $get): ?string
    {
        return self::modelFor($get('entity_type'));
    }

    /**
     * The class an entity type points at, or nothing: the wildcard, a loose
     * permission and a morph alias that no longer resolves all answer the same
     * way.
     *
     * `mixed` and not `?string`, because the two callers hand it two different
     * untyped things — what the form is holding, and what the row was saved
     * with — and narrowing the parameter would only move the check somewhere
     * less honest.
     *
     * @return class-string<Model>|null
     */
    private static function modelFor(mixed $type): ?string
    {
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

    /**
     * Whether the rule stored on this row can be handed back to the store.
     *
     * The builder writes the rule it could parse and `null` for everything
     * else, so a rule it cannot read back would be erased by a save that only
     * touched the title. Three things stop it reading one back — a shape it
     * refuses to draw, no model to check the columns against, and a column the
     * table no longer has — and all three are one question: does what is stored
     * survive the round trip?
     *
     * A row with nothing stored answers yes before any of that: writing `null`
     * over `null` loses nothing, and asking further would close the builder on
     * rows that have no rule to protect.
     *
     * Asked of the row and never of the entity picked on screen, because the
     * promise is about what is stored. That leaves the reset on `entity_type`
     * free to clear every rule this screen can read, which is every rule it had
     * any business clearing. Nothing here reads the store: `Narrowing::of()` is
     * pure and `Columns::of()` is memoised.
     */
    private static function conditionsWritable(?Model $record): bool
    {
        // Nothing is stored yet, so there is nothing to lose.
        if (! $record instanceof Model) {
            return true;
        }

        $stored = Narrowing::of($record);

        if (! $stored->isEditable()) {
            return false;
        }

        if ($record->getAttribute('options') === null) {
            return true;
        }

        $model = self::modelFor($record->getAttribute('entity_type'));

        return $model !== null && Narrowing::fromPayload(
            $stored->toPayload(),
            Columns::of($model),
            Columns::authority(),
            Ownership::of($model),
        ) instanceof Narrowing;
    }

    /**
     * Why the builder is closed, as the tail of a language key.
     *
     * A rule that was read and cannot be written back has no word of its own
     * yet, so it borrows the one for a shape the builder cannot draw. That
     * sentence names the wrong cause; a line of its own is a new key, which is a
     * minor, so it waits.
     */
    private static function lockedReason(Model $record): ?string
    {
        if (self::conditionsWritable($record)) {
            return null;
        }

        return Narrowing::of($record)->reason ?? 'shape';
    }

    private static function conditionsHelp(Get $get, ?Model $record): string
    {
        if (self::model($get) === null) {
            return (string) __('filament-warden::ui.conditions.no_model');
        }

        $reason = $record instanceof Model ? self::lockedReason($record) : null;

        return $reason === null
            ? (string) __('filament-warden::ui.conditions.warning')
            : (string) __('filament-warden::ui.conditions.locked.'.$reason);
    }

    /**
     * A permission is a shared row: every role and every account holding it
     * points at the same one, and editing it here moves the rule for all of them
     * at once. That is right for a catalogue — the row IS the rule — and it has
     * to be said from the FIRST holder and not the second, because one holder is
     * already somebody whose rule is about to move under them.
     *
     * The sentence is left exactly as 1.0.1 wrote it, plural and all: a wording
     * of its own would be a new key and therefore a minor, and an installation
     * that published this file goes on rendering its own copy unchanged. The
     * singular is 1.1.0.
     */
    private static function sharedWarning(?Model $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        $total = Holders::of($record)->total();

        return $total > 0
            ? (string) __('filament-warden::ui.resources.permissions.fields.conditions_shared', ['count' => $total])
            : null;
    }

    /**
     * Whether the tuple is already in the catalogue.
     *
     * A permission is (action, entity, record, ownership) — four columns with no
     * unique index behind them, so two rows agreeing on all four are creatable
     * and nothing short of all four describes one. Since 0.6.0 the sentence this
     * rule fires has promised "this name and entity"; the query compared the
     * name and stopped there, so the same action over two different models could
     * not coexist and a derived row could not be saved at all while a sibling
     * shared its name.
     *
     * The entity and the ownership are read from the form, because they are what
     * is about to be saved; the record's key is read from the row, because no
     * field on this screen can move it.
     *
     * A twin — the same tuple carrying conditions — is a row of its own and
     * collides with nothing, so only the plain rows are compared. That has to
     * be asked of the record being edited too, and not only of the candidates:
     * a twin's own name never changes underneath this rule, so a save that
     * only touches the title still runs it, and a plain sibling of the same
     * tuple — orphaned by warden's own `reconstrain()`, or still held by
     * another role — would otherwise read as a collision with itself.
     */
    private static function exists(mixed $name, ?Model $record, Get $get): bool
    {
        if ($record instanceof Model && $record->getAttribute('options') !== null) {
            return false;
        }

        $class = Context::resolve()->permissionClass();

        $entityType = self::entityType($get);
        $entityId = $record?->getAttribute('entity_id');

        return is_string($name) && $class::query()
            ->withoutGlobalScopes()
            ->where('name', $name)
            ->where('only_owned', (bool) $get('only_owned'))
            ->whereNull('options')
            // `Query\Builder::where()` already short-circuits a `null` value to
            // `whereNull()` on its own (`vendor/laravel/framework/…/Builder.php:
            // 986-991`), for both the two-arg form used here and the three-arg
            // `=` form, so a plain `where('entity_type', $entityType)` would read
            // the same rows. The explicit branch is defensive, not corrective:
            // it says what the query means without leaning on a Laravel internal
            // a reader of this file has no reason to have memorised, and it is
            // what the two tests below actually exercise.
            ->when(
                $entityType === null,
                static fn (mixed $query): mixed => $query->whereNull('entity_type'),
                static fn (mixed $query): mixed => $query->where('entity_type', $entityType),
            )
            ->when(
                $entityId === null,
                static fn (mixed $query): mixed => $query->whereNull('entity_id'),
                static fn (mixed $query): mixed => $query->where('entity_id', $entityId),
            )
            ->when($record instanceof Model, static fn (mixed $query): mixed => $query->whereKeyNot($record?->getKey()))
            ->exists();
    }
}
