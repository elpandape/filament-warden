<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

    /**
     * The visibility is the guarantee: a delete button asks
     * `getDeleteAuthorizationResponse()`, which goes straight to the policy, and
     * the orphan rule lives in `PermissionResource::canDelete()`, off that path.
     * The description is the table's own — the grants go with it by a foreign
     * key, below Eloquent and with no event of their own.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The delete takes its grants with it below Eloquent, and nothing in
            // warden bumps the version for a write made through the model layer:
            // without the hook every check goes on answering the old way,
            // silently and with no expiry. Void on purpose — whatever `after()`
            // returns stands in for the action's own result.
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => PermissionsTable::warning($record))
                ->visible(fn (Model $record): bool => PermissionResource::canDelete($record))
                ->after(static function (): void {
                    Warden::refresh();
                }),
        ];
    }

    /**
     * The title never catches up on its own: warden writes it in the `creating`
     * hook and only when it is null, so a rename leaves the old one in place,
     * lying. Blanking it does not help either — the hook is not consulted on an
     * update at all.
     *
     * So it is regenerated here, and only when nobody had written one by hand:
     * if what is being saved is exactly what warden would have generated for the
     * row as it was, then it was warden's and it may be warden's again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        // A disabled field is not dehydrated, so none of these four should be
        // in `$data` at all — and the guarantee is not left resting on how
        // another package derives that flag. What may not be edited is written
        // back exactly as it already was.
        //
        // The name and the entity go first, because everything below reads
        // `entity_type` as the entity about to be saved.
        if (! PermissionResource::mayEditName($record)) {
            $data['name'] = $record->getAttribute('name');
            $data['entity_type'] = $record->getAttribute('entity_type');
        }

        // Ownership and conditions narrow what the row means rather than
        // re-point it, so they answer their own config switch instead of
        // `mayEditName()`. Whichever screen greys either field out, the row
        // still deserves the same server-side floor as the name and the
        // entity above.
        if (! PermissionResource::mayEditOwnership($record)) {
            $data['only_owned'] = $record->getAttribute('only_owned');
        }

        // Half of what closes this builder is not mirrored above:
        // `PermissionForm::conditionsWritable()` also disables it — for a
        // corrupt blob, a shape the builder will not draw, an entity that no
        // longer resolves to a model, a column the table dropped, or a rule
        // that cannot be written back exactly as it is stored — and that
        // predicate is private to the form on purpose, the same reason
        // `ownable()` below is a private copy
        // rather than a public one. It is not duplicated here too. Its
        // ~25 lines of `Narrowing`/`Columns`/`Ownership` reads would not buy
        // a real floor: `EditRecord::save()` calls `$this->form->getState()`
        // BEFORE this method ever runs, against a schema rebuilt fresh for
        // this record on this request, so BOTH clauses of `disabled()` are
        // re-evaluated ahead of `$data` — no payload, forged or raced, can
        // carry `options` past a builder `conditionsWritable()` is closing.
        // Only removing `disabled()` itself could, and that is a mutation,
        // not a gap a duplicate here would close: it would only be able to
        // drift silently from the original, trading a provably unreachable
        // branch for a real one.
        if (! PermissionResource::mayEditConditions($record)) {
            $data['options'] = $record->getAttribute('options');
        }

        $entityType = $this->nullableText($this->submitted($data, 'entity_type'));

        // The toggle greys itself out where ownership cannot resolve, and a
        // greyed-out field sends nothing at all — so the `true` written for the
        // entity before this one would simply stay.
        //
        // Only when the entity moved. Giving up an ownership the row already
        // carried would widen what it grants, and nobody asked for that by
        // opening this screen.
        if ($entityType !== $record->getAttribute('entity_type') && ! $this->ownable($entityType)) {
            $data['only_owned'] = false;
        }

        $was = PermissionTitle::generate(
            $this->text($record->getAttribute('name')),
            $this->nullableText($record->getAttribute('entity_type')),
            null,
            (bool) $record->getAttribute('only_owned'),
        );

        if (($data['title'] ?? null) !== $was) {
            return $data;
        }

        $name = $this->text($this->submitted($data, 'name'));

        // A name this package minted has a title only this package can read back:
        // warden's generator has no way to know that `widget:` means anything.
        $data['title'] = PermissionName::title($name) ?? PermissionTitle::generate(
            $name,
            $entityType,
            null,
            (bool) $this->submitted($data, 'only_owned'),
        );

        return $data;
    }

    /**
     * Nothing in warden invalidates the check cache for a write made through the
     * model layer: only its own fluent actions bump the version, and the
     * `PermissionCreated`/`PermissionDeleted` events have no listener anywhere.
     * Without this, every check goes on answering the old way — silently, and
     * with no expiry.
     */
    protected function afterSave(): void
    {
        Warden::refresh();
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * What is being saved for a key, whether or not the form handed it over.
     *
     * A disabled field is not dehydrated — `disabled()` also calls `saved(false)`
     * — so Filament forgets its state path entirely and the key arrives ABSENT,
     * not null. At the shipped `permissions.update => 'loose'` that is every
     * field of a derived row but the title, and `$data['name'] ?? null` turned
     * the whole title into the empty string on a save that touched nothing.
     *
     * `array_key_exists`, and NOT `$data[$key] ?? $record->getAttribute($key)`:
     * absent and null are different answers here. Where the field is editable,
     * null is a person clearing the entity — the blank option — and the
     * coalescing form would quietly put the old entity straight back.
     *
     * @param  array<string, mixed>  $data
     */
    private function submitted(array $data, string $key): mixed
    {
        return array_key_exists($key, $data)
            ? $data[$key]
            : $this->getRecord()->getAttribute($key);
    }

    /**
     * Whether "only what it owns" can be resolved for an entity at all.
     *
     * The same question `PermissionForm` asks to grey the toggle out, asked
     * again here because a greyed-out toggle sends nothing and the stored value
     * would otherwise outlive the entity it was resolved against. §6.20: an
     * `only_owned` over an attribute that is not a column does not fail closed,
     * it emits invalid SQL and throws when the query runs.
     *
     * A private copy on purpose: making the form's own predicate public would be
     * new API surface and 1.0.2 is a patch. It is the fifth copy of the
     * morph-to-class idiom in this package — `Reach`, `Probe`, `Holders`,
     * `PermissionForm` and here — and folding the five into one reader is a
     * 1.1.0 item. Until then the two that must agree are held to account
     * together by 'switching the entity gives up an ownership it cannot
     * resolve', which asserts the screen and the column in one test.
     */
    private function ownable(?string $entityType): bool
    {
        if ($entityType === null) {
            return false;
        }

        // Warden's wildcard and a morph alias that no longer resolves both land
        // here as a string that is not a model class, which is the same answer.
        $model = Relation::getMorphedModel($entityType) ?? $entityType;

        return is_subclass_of($model, Model::class) && Ownership::of($model)->available;
    }
}
