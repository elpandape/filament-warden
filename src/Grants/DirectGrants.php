<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Expiry;
use ElPandaPe\Warden\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What one account holds without a role in between.
 *
 * `allow($account)->to(...)` writes a `grants` row whose authority is the
 * account. A permission handed straight to somebody is the hardest kind of
 * access to find later: it belongs to no role, so no role's grid shows it.
 *
 * Read against `grants` by hand and never through a relation, for the reasons
 * `Holders` gives.
 *
 * Read across every tenant — `withoutGlobalScopes()` — where
 * `Assignment::assignments()` and `RoleHolders` keep the active one. A write
 * here targets one exact scope, so a row from another tenant is shown, marked
 * and left alone: `disallow()` would delete nothing outside it and still
 * report success.
 */
final readonly class DirectGrants
{
    /**
     * @param  Model  $permission  the catalogue row the grant points at
     */
    public function __construct(
        public Model $permission,
        public bool $forbidden = false,
        public ?CarbonImmutable $ends = null,
        public bool $elsewhere = false,
    ) {}

    /**
     * Every direct grant this account holds, live rows only.
     *
     * Lapsed rows are dropped for the same reason `Assignment` drops them: a row
     * past its date authorises nothing, so a screen still listing it would offer
     * a revoke for access nobody has.
     *
     * @return list<self>
     */
    public static function of(Model $account): array
    {
        $context = Context::resolve();
        $permissionClass = $context->permissionClass();

        $grants = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $account->getMorphClass())
            ->where('entity_id', $account->getKey())
            ->tap(Expiry::live(...))
            ->get();

        if ($grants->isEmpty()) {
            return [];
        }

        // Keyed on the model rather than on a table name and a literal `id`: the
        // key name comes off the same class that runs the query, so an
        // installation that swaps the permission model for one with its own
        // `$primaryKey` still lines up.
        $permissions = $permissionClass::query()
            ->withoutGlobalScopes()
            ->whereIn(new $permissionClass()->getKeyName(), $grants->pluck('permission_id')->all())
            ->get()
            ->keyBy(static fn (Model $row): string => self::identifier($row->getKey()));

        $scope = app(Tenancy::class)->writeScope();

        $rows = [];

        foreach ($grants as $grant) {
            $permission = $permissions->get(self::identifier($grant->getAttribute('permission_id')));

            if (! $permission instanceof Model) {
                continue; // @codeCoverageIgnore
            }

            $ends = $grant->getAttribute('expires_at');

            $rows[] = new self(
                permission: $permission,
                forbidden: (bool) $grant->getAttribute('forbidden'),
                ends: $ends instanceof DateTimeInterface ? CarbonImmutable::instance($ends) : null,
                elsewhere: ! self::sameScope($grant->getAttribute('scope'), $scope),
            );
        }

        return $rows;
    }

    /**
     * The catalogue rows this screen may offer, searched in the store.
     *
     * The union of every panel's catalogue and not one panel's: a grant is a row
     * of the store, and an account can be handed something another panel
     * declares. What is left OUT is the wildcard — `entity_type = '*'` is not a
     * row any screen in this package hands out, and handing it to an account
     * from a dropdown would be the one write that gives away everything.
     *
     * @return array<int|string, string>
     */
    public static function offerable(string $search): array
    {
        $catalog = Catalog::union(array_values(Filament::getPanels()));

        $names = [];

        foreach ($catalog->entries as $entry) {
            $names[] = $entry->name;
        }

        if ($names === []) {
            return []; // @codeCoverageIgnore
        }

        $rows = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereIn('name', array_values(array_unique($names)))
            ->where(static fn (Builder $query): Builder => $query->whereNull('entity_type')->orWhere('entity_type', '!=', '*'))
            ->when($search !== '', static function (Builder $query) use ($search): void {
                // `!` as the escape character and the clause spelled out, for
                // the reason `ViewPermission::accounts()` gives: without the
                // clause SQLite matches nothing at all, and a backslash is a
                // syntax error on MySQL.
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

                $query->where(static function (Builder $inner) use ($term): void {
                    $inner->whereRaw("name like ? escape '!'", [$term])
                        ->orWhereRaw("title like ? escape '!'", [$term]);
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $key = $row->getKey();

            if (is_int($key) || is_string($key)) {
                $options[$key] = Holders::label($row);
            }
        }

        return $options;
    }

    /**
     * Whether the signed-in account may write direct grants at all.
     *
     * `update` over a permission, the same ability the permission's own hand-out
     * asks for and for the same reason: this is the way back from that screen,
     * and two doors onto one write cannot ask two different questions.
     */
    public static function mayWrite(Model $permission): bool
    {
        return Access::grantedToCurrentUser('update', $permission);
    }

    public static function permission(mixed $key): ?Model
    {
        return is_int($key) || is_string($key)
            ? Context::resolve()->permissionClass()::query()->withoutGlobalScopes()->whereKey($key)->first()
            : null;
    }

    /**
     * Writes one direct grant, in the polarity asked for.
     *
     * Granting and forbidding COEXIST in `grants` — `forbidden` is part of the
     * unique index — so a screen that only wrote the new state would leave both
     * rows behind and a cell in two states at once. Each write is paired with
     * the removal of its opposite, which is the same rule the grid follows for
     * every step of its cycle.
     *
     * `until()` before `to()`, because a grant executes on `to()` and warden
     * throws rather than let a date be added afterwards. A prohibition takes no
     * date at all: `ForbidsPermissions::until()` throws unconditionally, `null`
     * included, so the two branches are two chains and not one with an argument.
     */
    public static function write(Model $account, Model $permission, bool $forbidding, ?DateTimeInterface $until): bool
    {
        if (! self::mayWrite($permission)) {
            return false;
        }

        if ($forbidding) {
            Warden::disallow($account)->to($permission);
            Warden::forbid($account)->to($permission);

            return true;
        }

        Warden::unforbid($account)->to($permission);
        Warden::allow($account)->until($until)->to($permission);

        return true;
    }

    /**
     * Takes a direct grant back, whichever polarity it was written in.
     *
     * Both are sent because the row says which one exists and a caller that
     * guessed would leave the other behind. Counted rather than trusted:
     * neither `disallow()` nor `unforbid()` reports what it deleted, and a
     * write aimed at another tenant's scope removes nothing while raising no
     * error at all — so success is judged by the row being gone.
     */
    public static function revoke(Model $account, Model $permission): bool
    {
        if (! self::mayWrite($permission)) {
            return false;
        }

        Warden::disallow($account)->to($permission);
        Warden::unforbid($account)->to($permission);

        return ! Context::resolve()->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $account->getMorphClass())
            ->where('entity_id', $account->getKey())
            ->where('permission_id', $permission->getKey())
            ->exists();
    }

    /**
     * The word this row's reach reads as.
     *
     * A rule pinned to one record answers no class check and no shape describes
     * it, so it is asked about first — the same order `PermissionInfolist` uses,
     * and both change together or the two screens disagree about one row.
     */
    public function reach(): string
    {
        return $this->permission->getAttribute('entity_id') !== null
            ? 'record'
            : Narrowing::of($this->permission)->shape->value;
    }

    private static function sameScope(mixed $left, mixed $right): bool
    {
        return $left === null && $right === null
            || $left !== null && $right !== null && self::identifier($left) === self::identifier($right);
    }

    /**
     * A key narrowed to text, and an empty string for anything that does not
     * read as a key — which matches nothing, and losing a row is the safe way
     * to fail here.
     */
    private static function identifier(mixed $key): string
    {
        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
