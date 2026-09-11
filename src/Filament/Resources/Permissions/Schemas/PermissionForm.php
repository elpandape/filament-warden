<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Filament\Forms\ConditionBuilder;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Constraints\ConstraintSerializer;
use ElPandaPe\Warden\Constraints\Group;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use ElPandaPe\Warden\Tenancy\Tenancy;
use ElPandaPe\Warden\Tenancy\TenantScope;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group as Column;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What a permission is, and how far it reaches — in that order.
 *
 * Every field says why it is closed when it is closed. A screen that greys a
 * control out and explains nothing is a screen that gets worked around.
 */
final class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Four tracks, so the form keeps three of them and the aside keeps
            // one: a form is read left to right and what a row costs is read
            // beside it, not underneath. Below the split's own breakpoint
            // Filament stacks the two, which is the reading a phone gets.
            ->columns(4)
            ->components([
                // Aliased, because `ElPandaPe\Warden\Constraints\Group` is
                // already in this file and is the one the satisfiability check
                // reads — the name belongs to warden's rule, not to a layout.
                Column::make()
                    ->columnSpan(3)
                    ->schema([
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
                                    // true — and an `only_owned` warden cannot express in SQL
                                    // says nothing about it: the branch is skipped on the grant
                                    // pass and blocks on the forbid pass, without an error
                                    // anywhere.
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
                    ]),

                // Read-only, and on purpose: the date belongs to a GRANT and not
                // to this row, so a permission has no date of its own to edit —
                // which is exactly the thing people get wrong about expiry, and
                // saying it beside the count is cheaper than saying it in a
                // paragraph nobody reads.
                Column::make()
                    ->columnSpan(1)
                    ->schema([
                        Section::make(__('filament-warden::ui.resources.permissions.sections.held'))
                            ->icon(Heroicon::OutlinedUsers)
                            ->visible(static fn (?Model $record): bool => $record instanceof Model)
                            ->schema([
                                TextEntry::make('held')
                                    ->hiddenLabel()
                                    ->state(static fn (?Model $record): string => $record instanceof Model
                                        ? PermissionsTable::warning($record)
                                        : ''),
                            ]),

                        Section::make(__('filament-warden::ui.resources.permissions.sections.expiry'))
                            ->icon(Heroicon::OutlinedClock)
                            ->schema([
                                TextEntry::make('expiry')
                                    ->hiddenLabel()
                                    ->state(static fn (?Model $record): string => self::expiry($record)),
                            ]),
                    ]),
            ]);
    }

    /**
     * Every entity any panel knows about, plus warden's wildcard and the loose
     * permission that points at nothing.
     *
     * And the row's own entity, always. The catalogue now spans every panel, so
     * a row derived from another panel's resource is offered like any other —
     * but a row whose entity no panel declares at all (a morph alias left over,
     * a model dropped everywhere) still has to be offered, or the select would
     * refuse the value it was drawn with and the row could not be opened.
     *
     * @return array<string, string>
     */
    public static function entities(?Model $record = null): array
    {
        $options = ['*' => (string) __('filament-warden::ui.resources.permissions.entity.any')];

        foreach (Catalog::union(array_values(Filament::getPanels()))->entries as $entry) {
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
     * How many of this row's grants end, and when the first of them does.
     *
     * A permission does not expire and neither does a role: what expires is the
     * GRANT that points at one, and the assignment that reaches it. So this
     * counts rather than offers — there is no date on this record to edit, and
     * a field here would be a field that writes nothing.
     *
     * Read across every tenant, like `Holders`: a grant ends or it does not, and
     * that question has no scope.
     */
    private static function expiry(?Model $record): string
    {
        if (! $record instanceof Model) {
            return (string) __('filament-warden::ui.resources.permissions.expiry.none');
        }

        $ends = Context::resolve()->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('permission_id', $record->getKey())
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->value('expires_at');

        if (! $ends instanceof DateTimeInterface) {
            return (string) __('filament-warden::ui.resources.permissions.expiry.none');
        }

        $count = Context::resolve()->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('permission_id', $record->getKey())
            ->whereNotNull('expires_at')
            ->count();

        return trans_choice('filament-warden::ui.resources.permissions.expiry.some', $count, [
            'first' => CarbonImmutable::instance($ends)->toDayDateTimeString(),
        ]);
    }

    /**
     * Why this field is what it is, in three cases and never a fourth.
     *
     * A derived permission's name is written by the policy method that declares
     * it. Changing it does not break anything loudly — it disconnects the row
     * from the code that asks for it, and nothing says so afterwards.
     *
     * A loose row this installation would let anyone edit, and does not, is
     * closed by a HOLDER: `mayEditName()` refuses a row that is not orphaned,
     * and re-pointing it would move what every one of them holds without telling
     * any of them. That is the one case that has to be said out loud, because
     * the field is greyed and the reason is somebody else's grant.
     *
     * Asked as `mayEdit() && ! mayEditName()` and never as `! mayEditName()`
     * alone: the second is also false when the INSTALLATION closed this whole
     * class of row — `permissions.update` at `'title'`, or a derived row under
     * `'loose'` — where no holder is involved and claiming one would be a lie on
     * a security screen. `mayEdit()` reads config and one attribute and touches
     * no table, so putting it first also keeps the grant read out of every case
     * that does not need it.
     */
    private static function nameHelp(?Model $record): string
    {
        if (! $record instanceof Model) {
            return (string) __('filament-warden::ui.resources.permissions.fields.name_help_loose');
        }

        if ($record->getAttribute('entity_type') !== null) {
            return (string) __('filament-warden::ui.resources.permissions.fields.name_help_derived');
        }

        return PermissionResource::mayEdit($record) && ! PermissionResource::mayEditName($record)
            ? (string) __('filament-warden::ui.resources.permissions.fields.name_help_held')
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

        return Morph::model($type);
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

        if ($ownership->available) {
            return (string) __('filament-warden::ui.resources.permissions.fields.only_owned_help');
        }

        // Two refusals, two sentences. Naming a column when the installation
        // registered no resolver at all points at the wrong thing to go and fix.
        if (! $ownership->resolved) {
            return (string) __('filament-warden::ui.conditions.no_ownership_resolver');
        }

        return (string) __('filament-warden::ui.conditions.no_ownership', [
            'table' => new $model()->getTable(),
            'column' => $ownership->column ?? '',
        ]);
    }

    /**
     * Whether this screen may write the rule the row already has.
     *
     * Kept as its own reading of `lockedReason()` because the two `disabled()`
     * callbacks want a boolean and the hint wants a sentence, and they must never
     * be able to disagree.
     */
    private static function conditionsWritable(?Model $record): bool
    {
        return self::lockedReason($record) === null;
    }

    /**
     * Why the builder is closed, as the tail of a language key — or null.
     *
     * The question is not whether the stored rule can be READ. It is whether what
     * is stored survives being read and written back unchanged: a value stored as
     * the string `'2'` comes back as the integer `2`, and an `or` on the first
     * line comes back as `and`, because `Narrowing::conditions()` normalises it.
     * Both change what the row means and both stop it matching its own twin, and
     * a save that only touched the title would do it without a word.
     *
     * Nothing here reads the store: `Narrowing::of()` is pure and `Columns::of()`
     * is memoised.
     */
    private static function lockedReason(?Model $record): ?string
    {
        if (! $record instanceof Model) {
            return null;
        }

        $stored = Narrowing::of($record);

        // A shape this screen refuses to draw already carries its own word.
        if (! $stored->isEditable()) {
            return $stored->reason ?? 'shape';
        }

        // Nothing stored, nothing to lose: writing null over null keeps no rule
        // from anybody, and asking further would close the builder on every plain
        // row in the catalogue.
        //
        // The cast is the right question HERE, and only because the guard above
        // already answered the wrong one. `Narrowing::of()` reads the column, so
        // the three values Eloquent flattens to null — text that is not JSON,
        // the empty string, the JSON literal `null` — come back `Unreadable` and
        // leave with their own word. What reaches this line is a row whose blob
        // decodes, where cast and column say the same thing.
        if ($record->getAttribute('options') === null) {
            return null;
        }

        $model = self::modelFor($record->getAttribute('entity_type'));

        if ($model === null) {
            return 'model';
        }

        // Ahead of the round trip on purpose. A rule that can never be true
        // reads and writes back perfectly well — the round trip has no opinion
        // on whether anything could ever match it — so asking afterwards would
        // let the builder open on a rule warden refuses, and the save would come
        // back with a field error the person could not act on from a form that
        // said everything was fine.
        if ($stored->unsatisfiableColumns($model) !== []) {
            return 'unsatisfiable';
        }

        $rebuilt = Narrowing::fromPayload(
            $stored->toPayload(),
            Columns::of($model),
            Columns::authority(),
            Ownership::of($model),
        );

        if (! $rebuilt instanceof Narrowing) {
            return 'column';
        }

        $group = $rebuilt->toGroup();

        // A statement of its own, not folded into a ternary: the parity test in
        // `LanguageTest` finds a reason by matching a plain return of a string
        // literal on one line, and a ternary wrapped around the multi-line call
        // above it would never put that text on a line by itself.
        if ($group instanceof Group && ConstraintSerializer::sameRule(
            ConstraintSerializer::serialize($group),
            $record->getAttribute('options'),
        )) {
            return null;
        }

        return 'rewrite';
    }

    private static function conditionsHelp(Get $get, ?Model $record): string
    {
        if (self::model($get) === null) {
            return (string) __('filament-warden::ui.conditions.no_model');
        }

        $reason = self::lockedReason($record);

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
     * The sentence names its count after a label — "Holders of this row: 1" —
     * because it fires from the FIRST holder and a count placed in front of a
     * noun would have to agree with it. `trans_choice()` would be the other way
     * out and it is not one: the sibling sentence on the delete modal carries
     * three counts and Laravel pluralises on one, so both are worded to read
     * correctly at 0, 1 and 40 instead. Still one key, so an installation that
     * published `lang/{en,es}/ui.php` keeps its own copy — and its own old plural.
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
     * A permission is (action, entity, record, ownership, TENANT) — and warden
     * has had a unique index over `(name, identity_key)` since its own 2.0,
     * where that key is a digest of exactly those. Since 0.6.0 the sentence this
     * rule fires has promised "this name and entity"; the query compared the
     * name and stopped there, so the same action over two different models could
     * not coexist and a derived row could not be saved at all while a sibling
     * shared its name.
     *
     * It also read the catalogue with every scope dropped, which made it refuse
     * a name another tenant held — a collision the index would never have
     * raised. The scope is now part of the question, asked the way `stampScope()`
     * decides it, which is what closes that half.
     *
     * The other half stays open on purpose. A DUPLICATE TWIN — two rows agreeing
     * on all five AND on their conditions — is invisible here, because the digest
     * needs the value `options` is about to take and this rule runs BEFORE the
     * condition builder dehydrates and before `mutateFormDataBeforeSave()`. There
     * is no honest answer to give at this point in the lifecycle, so the backstop
     * on `CreatePermission`/`EditPermission` catches that one after the write and
     * reports it on this same field. Two guards, one because the other cannot
     * reach.
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
        // The column, not the cast. A twin whose blob does not decode casts to
        // null, so this short-circuit would miss it and compare it against the
        // plain rows as though it were one — refusing a name the database would
        // have taken, on a row the person cannot fix from here anyway.
        if ($record instanceof Model && ($record->getAttributes()['options'] ?? null) !== null) {
            return false;
        }

        $class = Context::resolve()->permissionClass();

        $entityType = self::entityType($get);
        $entityId = $record?->getAttribute('entity_id');

        // The scope this row would be written at, asked the way warden asks it:
        // `stampScope()` stamps a catalogue row with the active tenant unless
        // `scope.only_relations` keeps the catalogue global, in which case every
        // permission is written at NULL. Warden's unique index is over
        // `(name, identity_key)` and that digest carries the tenant, so two rows
        // of the same name at different tenants are two rows it admits — while a
        // read with every scope dropped saw the neighbour's and refused a name
        // the database would have taken.
        $tenancy = app(Tenancy::class);
        $scope = $tenancy->scopesCatalog() ? $tenancy->current() : null;

        return is_string($name) && $class::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('name', $name)
            ->when(
                $scope === null,
                static fn (mixed $query): mixed => $query->whereNull('scope'),
                static fn (mixed $query): mixed => $query->where('scope', $scope),
            )
            ->where('only_owned', (bool) $get('only_owned'))
            ->whereNull('options')
            // `Query\Builder::where()` already short-circuits a `null` value to
            // `whereNull()` on its own, for both the two-arg form used here and
            // the three-arg
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
