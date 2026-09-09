<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Support\Config as WardenConfig;
use ElPandaPe\Warden\Support\Expiry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class RolesTable
{
    /**
     * A memo apiece for the two closures below — `$heldCounts` for the
     * informing column, `$assignedRoleIds` for the deciding button — each one
     * query the first row asks for and every row after reuses.
     *
     * Neither is bounded by the PAGE: a column closure cannot reach the record
     * set Filament paginated. Both are bounded by the CATALOGUE instead, one
     * row per role however many assignments exist — which a statement count
     * cannot see, so `RoleResourceTest` counts hydrated rows as well.
     *
     * Never merged into one query: they answer different questions under
     * different scope rules. `$assignedRoleIds` reads wide because it feeds a
     * DELETE and the cascade is blind to tenancy; `$heldCounts` keeps the
     * active tenant because it only INFORMS, and a wider number is one
     * `retract()` could not act on from here.
     *
     * Local variables rather than static properties: a fresh pair per
     * `configure()`, so nothing outlives the render or leaks between tests.
     */
    public static function configure(Table $table): Table
    {
        // Anotados: los dos viajan por referencia a varios cierres, y a
        // `level: max` una variable capturada por referencia es `mixed` desde el
        // segundo lector — cualquiera de ellos puede haberla escrito.
        /** @var array<int|string, array{held: int, ending: int}>|null $heldCounts */
        $heldCounts = null;
        /** @var array<int|string, int>|null $ruleCounts */
        $ruleCounts = null;
        $assignedRoleIds = null;
        $inherits = null;

        return $table
            ->defaultSort('name')
            ->columns([
                // Una columna y no dos, como el boceto y como la tabla de
                // permisos ya hacen: la fila se lee por cómo la gente LLAMA al
                // rol, y el nombre de código va debajo en mono porque es lo que
                // las concesiones apuntan. Dos columnas obligaban a mirar dos
                // sitios para identificar una fila.
                //
                // Ordena y busca por el TÍTULO, que es lo que se ve; el nombre
                // entra en la búsqueda igualmente, porque quien lo teclea sabe
                // exactamente lo que busca.
                TextColumn::make('title')
                    ->label(__('filament-warden::ui.resources.roles.columns.role'))
                    ->description(static fn (Model $record): string => self::nameOf($record))
                    ->placeholder(static fn (Model $record): string => self::nameOf($record))
                    ->searchable(['name', 'title'])
                    ->sortable(),

                // Neither sortable nor searchable: both fall back to the
                // column's own name and would ask the database for a column
                // that does not exist — an error at click time, not at build
                // time (§6.17).
                TextColumn::make('inherits')
                    ->label(__('filament-warden::ui.resources.roles.columns.inherits'))
                    ->badge()
                    ->placeholder('—')
                    // Three and a tally, never the whole list: a role can inherit
                    // from a dozen and the column is one cell wide. `+n` is the
                    // same shape the holders sentence has used since 1.0.
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->visible(static fn (): bool => WardenConfig::nestedRoles())
                    ->state(static function (Model $record) use (&$inherits): array {
                        $inherits ??= self::inheritsFrom();

                        $key = $record->getKey();

                        return (is_int($key) || is_string($key)) ? ($inherits[$key] ?? []) : [];
                    }),

                // Cuántas reglas ha escrito el rol. Del mismo agrupado por página
                // que los titulares y por el mismo motivo: una consulta por fila
                // es lo que la v1.5.0 midió en 22 MB.
                //
                // Lo que ESCRIBIÓ y no lo que contesta: contestar exige resolver
                // el catálogo entero por rol, que es el coste que esta tabla no
                // puede pagar. Un rol con el comodín escribe una regla y contesta
                // todas, y esa distinción la cuenta la rejilla, que es donde se
                // ve.
                TextColumn::make('rules')
                    ->label(__('filament-warden::ui.resources.roles.columns.rules'))
                    ->badge()
                    ->color('gray')
                    ->state(static function (Model $record) use (&$ruleCounts): int {
                        // Estrechado en una local: a `level: max` una variable
                        // capturada por referencia y leída desde más de un
                        // cierre vuelve a ser `mixed` en cada lectura, porque
                        // cualquiera de ellos pudo escribirla. El `@var` de la
                        // declaración no alcanza; el de aquí sí.
                        /** @var array<int|string, int> $counts */
                        $counts = $ruleCounts ??= self::ruleCounts();

                        $key = $record->getKey();

                        return is_int($key) || is_string($key) ? ($counts[$key] ?? 0) : 0;
                    }),

                TextColumn::make('held')
                    ->label(__('filament-warden::ui.resources.roles.columns.held'))
                    ->badge()
                    ->state(static function (Model $record) use (&$heldCounts): int {
                        /** @var array<int|string, array{held: int, ending: int}> $counts */
                        $counts = $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();

                        return (is_int($key) || is_string($key)) ? ($counts[$key]['held'] ?? 0) : 0;
                    })
                    // Under the count and not beside it: it is a subset of the
                    // number above, so a second badge would read as a second
                    // population. Absent when there is none, rather than a zero
                    // nobody needs.
                    ->description(static function (Model $record) use (&$heldCounts): ?string {
                        /** @var array<int|string, array{held: int, ending: int}> $counts */
                        $counts = $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();
                        $ending = (is_int($key) || is_string($key)) ? ($counts[$key]['ending'] ?? 0) : 0;

                        return $ending === 0
                            ? null
                            : trans_choice('filament-warden::ui.resources.roles.columns.ending', $ending);
                    }),
            ])
            ->recordActions([
                // The screen it opens has existed since `v0.4.0` and nothing
                // ever pointed at it: the route was registered, the page was
                // written and tested, and the listing offered edit and delete
                // only — so the only way in was typing the URL. It is §6.23 one
                // screen over, and the same cure: Filament adds no action a
                // table does not declare.
                //
                // No `visible()` of its own, unlike the two below it. The
                // resource leaves `canView()` alone, so the policy closes this
                // on its own; the config rules that make `canEdit()`/
                // `canDelete()` say more than the policy does have no reading
                // half to speak of.
                ViewAction::make(),

                EditAction::make(),
                // The config and the protected list have their say before the
                // policy does; the action disappears rather than failing later.
                DeleteAction::make()
                    ->modalDescription(static fn (Model $record): string => self::warning($record))
                    ->visible(static function (Model $record) use (&$assignedRoleIds): bool {
                        // Fetched only under the one rule that ever reads it
                        // (`RoleResource::isDeletable()`'s own `'all'`/`false`
                        // branches never query at all): a page where nothing
                        // asks about it pays nothing.
                        if (Config::get('roles.delete') === 'unassigned') {
                            $assignedRoleIds ??= self::assignedRoleIds();
                        }

                        return RoleResource::canDelete($record, $assignedRoleIds);
                    }),
            ]);
    }

    /**
     * What goes away with it, said the way `PermissionsTable::warning()`
     * already says it — public for the same reason (§6.23): `EditRole` and
     * `ViewRole` carry their own `DeleteAction` and reuse this directly.
     *
     * Read wide on purpose: the assignment rows follow the role down through a
     * foreign key and THE CASCADE IS BLIND TO THE SCOPE — and to the clock —
     * exactly like `RoleResource::isDeletable()`'s own read and `Holders::of()`'s.
     * Counting only the active tenant's live rows would promise a smaller loss
     * than the delete actually causes.
     */
    public static function warning(Model $record): string
    {
        $rows = self::assignments($record);

        if ($rows->isEmpty()) {
            return (string) __('filament-warden::ui.resources.roles.delete.nobody');
        }

        return (string) __('filament-warden::ui.resources.roles.delete.holders', [
            'count' => $rows->count(),
            'names' => implode(', ', self::labels($rows)),
        ]);
    }

    /**
     * Who holds it, from rows the CALLER already scoped: a pure transform, so
     * the decision that matters — wide for a delete warning, tenant-scoped for
     * an informational section — stays in the caller's query.
     *
     * Simpler than `Holders::labels()` in one way: `assigned_roles`'s two
     * authority columns are `NOT NULL`, unlike the `grants` ones, so there is
     * no "granted to everyone" bucket and no line only a null would reach.
     *
     * @param  Collection<int, Model>  $rows
     * @return list<string>
     */
    public static function labels(Collection $rows): array
    {
        /** @var array<string, list<int|string>> $byType */
        $byType = [];
        $counted = 0;

        foreach ($rows as $row) {
            if ($counted >= Holders::LABELS) {
                break;
            }

            $type = $row->getAttribute('entity_type');
            $key = $row->getAttribute('entity_id');

            if (is_string($type) && (is_int($key) || is_string($key))) {
                $byType[$type][] = $key;
                $counted++;
            }
        }

        $labels = [];

        foreach ($byType as $type => $keys) {
            // A stale morph alias does not throw, it stops resolving — and an
            // authority nobody can name is simply left out, the same choice
            // `Holders::accounts()` makes.
            $class = Morph::model($type);

            if ($class === null) {
                continue;
            }

            $records = $class::query()->withoutGlobalScopes()->whereKey($keys)->get();

            foreach ($records as $holder) {
                $labels[] = Holders::label($holder);
            }
        }

        return $labels;
    }

    /**
     * How many rows each role has in `assigned_roles`, scoped, one row per
     * role: a badge that informs keeps its scope, or it shows a number
     * `retract()` from this screen could not act on.
     *
     * The aggregate is what bounds it — reducing in PHP answers the same
     * question and hydrates the whole table, which a statement count cannot
     * see. A holder restricted to a context counts like any other.
     *
     * `count(*)` is whatever the driver hands back and `AssignedRole` declares
     * no casts, so it is narrowed with `is_numeric()` rather than assumed. The
     * other values this class reads are keys, not aggregates, and take
     * `is_int() || is_string()`.
     *
     * @return array<int|string, array{held: int, ending: int}>
     */
    /**
     * El nombre de código de un rol, o nada si la fila no lo lleva.
     *
     * Leído con guarda porque la suite corre bajo `Model::shouldBeStrict()` y
     * el modelo es el que la instalación configure: preguntar por una columna
     * que no tiene lanza.
     */
    private static function nameOf(Model $record): string
    {
        $name = $record->getAttribute('name');

        return is_string($name) ? $name : '';
    }

    /**
     * Cuántas filas de `grants` ha escrito cada rol, en una consulta por página.
     *
     * Sin `Expiry::live()`: una concesión vencida SIGUE escrita, y esta columna
     * cuenta lo que hay en la tienda, no lo que contesta hoy. Quitarla de aquí
     * haría que un rol pareciera no tener nada mientras sus filas siguen ahí
     * bloqueando su borrado.
     *
     * @return array<int|string, int>
     */
    private static function ruleCounts(): array
    {
        $counts = [];

        $context = Context::resolve();

        $rows = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->where('entity_type', new ($context->roleClass())()->getMorphClass())
            ->select('entity_id')
            ->selectRaw('count(*) as written')
            ->groupBy('entity_id')
            ->get();

        foreach ($rows as $row) {
            $key = $row->getAttribute('entity_id');
            $written = $row->getAttribute('written');

            if ((is_int($key) || is_string($key)) && is_numeric($written)) {
                $counts[$key] = (int) $written;
            }
        }

        return $counts;
    }

    /**
     * How many rows each role has in `assigned_roles`, scoped, one row per
     * role: a badge that informs keeps its scope, or it shows a number
     * `retract()` from this screen could not act on.
     *
     * The aggregate is what bounds it — reducing in PHP answers the same
     * question and hydrates the whole table, which a statement count cannot
     * see. A holder restricted to a context counts like any other.
     *
     * `count(*)` is whatever the driver hands back and `AssignedRole` declares
     * no casts, so it is narrowed with `is_numeric()` rather than assumed. The
     * other values this class reads are keys, not aggregates, and take
     * `is_int() || is_string()`.
     *
     * @return array<int|string, array{held: int, ending: int}>
     */
    private static function heldCounts(): array
    {
        $counts = [];

        $rows = Context::resolve()->assignedRoleClass()::query()
            ->select('role_id')
            ->selectRaw('count(*) as held')
            // The second figure rides the SAME query as the first. A column of
            // its own would be a second grouped read per page for a number that
            // is a subset of one already in hand — and this table's cost is
            // bounded by a test that counts statements AND hydrated rows, so a
            // second one shows up.
            ->selectRaw('sum(case when expires_at is not null then 1 else 0 end) as ending')
            // A badge that informs counts what somebody actually holds. The two
            // reads below it decide a DELETE and count the lapsed rows too,
            // because the cascade removes them all the same.
            ->tap(Expiry::live(...))
            ->groupBy('role_id')
            ->get();

        foreach ($rows as $row) {
            $key = $row->getAttribute('role_id');
            $held = $row->getAttribute('held');
            $ending = $row->getAttribute('ending');

            if ((is_int($key) || is_string($key)) && is_numeric($held)) {
                $counts[$key] = [
                    'held' => (int) $held,
                    'ending' => is_numeric($ending) ? (int) $ending : 0,
                ];
            }
        }

        return $counts;
    }

    /**
     * Which roles each role was GIVEN, named.
     *
     * Two reads for the whole table and never one per row: this is the listing
     * v1.5.0 measured at 22 MB when a fix counted statements and not rows.
     *
     * Not scoped to the page, and that is the cheaper half rather than the
     * lazier one: the rows are role-to-role EDGES, which are bounded by the
     * catalogue and are the rarest thing in this schema — a `whereIn` on the
     * page's keys would add a binding list per render to save nothing, and
     * `heldCounts()` beside it already groups over the whole table for the same
     * reason.
     *
     * Direct edges only — what somebody chose — because the rest is what the
     * choice brought along, and a chip saying so would be a chip nobody put
     * there.
     *
     * `warden.roles.nested` is asked ONCE, on the column's `visible()`, and not
     * again here. A hidden column never evaluates its state, so a second check
     * would be a line nothing can reach — and this project runs its coverage
     * gate at 100% with no baseline, which is how that showed up rather than
     * sitting there looking careful.
     *
     * Empty when nesting is off, and asked here rather than of warden's closure
     * for the same reason `RoleGrants` asks it: `RoleClosure::for()` returns
     * direct edges under either setting, because for an ACCOUNT those are simply
     * its roles.
     *
     * @return array<int|string, list<string>>
     */
    private static function inheritsFrom(): array
    {
        $context = Context::resolve();
        $roleClass = $context->roleClass();

        $edges = $context->assignedRoleClass()::query()
            ->where('entity_type', new $roleClass()->getMorphClass())
            ->tap(Expiry::live(...))
            ->get(['entity_id', 'role_id']);

        if ($edges->isEmpty()) {
            return [];
        }

        $named = [];

        foreach ($roleClass::query()->whereKey($edges->pluck('role_id')->all())->get() as $inner) {
            $key = $inner->getKey();

            if (is_int($key) || is_string($key)) {
                $named[$key] = Holders::label($inner);
            }
        }

        $chips = [];

        foreach ($edges as $edge) {
            $outer = $edge->getAttribute('entity_id');
            $inner = $edge->getAttribute('role_id');
            $label = (is_int($inner) || is_string($inner)) ? ($named[$inner] ?? null) : null;

            if ((is_int($outer) || is_string($outer)) && $label !== null) {
                $chips[$outer][] = $label;
            }
        }

        return $chips;
    }

    /**
     * Every role id with at least one assignment row, ANY tenant and ANY date:
     * this decides a DELETE, and the cascade that removes those rows is blind to
     * both. `heldCounts()` above is the other kind and applies warden's boundary,
     * so the badge and the delete button can honestly disagree — one says who
     * holds the role, the other says what the delete would destroy.
     *
     * `distinct()` is what bounds it to the catalogue rather than the
     * assignment table.
     *
     * A set rather than a list, so `isDeletable()` does one `isset()` per row.
     * No cast either way: PHP normalises a canonical numeric string key back to
     * `int`, so a set built from either type answers for either.
     *
     * @return array<int|string, true>
     */
    private static function assignedRoleIds(): array
    {
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->distinct()
            ->get(['role_id']);

        $ids = [];

        foreach ($rows as $row) {
            $key = $row->getAttribute('role_id');

            if (is_int($key) || is_string($key)) {
                $ids[$key] = true;
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Model>
     */
    private static function assignments(Model $record): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->where('role_id', $record->getKey())
            ->orderBy('id')
            ->get();

        return $rows;
    }
}
