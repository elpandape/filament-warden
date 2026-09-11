<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Checks\Explain\Cause as WardenCause;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Expiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What the store answers for one account, asked out loud.
 *
 * `explain()` put on screen: pick an account, optionally a record, and warden
 * says the verdict and the cause. It is the only place in the panel where the
 * question is asked the way the application asks it — with a real authority and
 * a real row — rather than about a class with nothing in front of it.
 *
 * Which is also why it is worth having: on a class check warden never tests a
 * narrowed rule — no condition is evaluated and no ownership resolved — and
 * here, with a record chosen, both are.
 */
final readonly class Probe
{
    public function __construct(
        public Stance $verdict,
        public Cause $cause,
        public string $summary,
        public ?string $permission = null,
        public ?string $role = null,
        public ?string $note = null,
        public ?string $rule = null,
        public ?string $via = null,
        public ?string $until = null,
    ) {}

    /**
     * @param  Model  $authority  the account the question is asked for
     * @param  Model  $permission  the catalogue row being examined
     * @param  int|string|null  $recordKey  the row to put in front of it, if any
     */
    public static function run(Model $authority, Model $permission, int|string|null $recordKey = null): self
    {
        $name = $permission->getAttribute('name');

        if (! is_string($name)) {
            return self::refused('unreadable');
        }

        $entity = self::entity($permission);

        if ($entity === false) {
            return self::refused('unresolved');
        }

        if ($recordKey !== null) {
            // A permission with no model behind it is asked without one. Putting
            // a row in front of it would be answering a different question.
            if (! is_string($entity) || $entity === '*' || ! is_subclass_of($entity, Model::class)) {
                return self::refused('no_model');
            }

            $record = $entity::query()->withoutGlobalScopes()->whereKey($recordKey)->first();

            // A key that names no row is not the same answer as "nothing
            // matched", and warden cannot tell them apart: with no row it would
            // be asked about the class and would answer about the class.
            if (! $record instanceof Model) {
                return self::refused('no_record');
            }

            $entity = $record;
        }

        $why = Warden::explain($authority, $name, $entity);

        $cause = Cause::of($why->cause);

        $verdict = match (true) {
            $why->verdict->isGranted() => Stance::Granted,
            $why->verdict->isForbidden() => Stance::Forbidden,
            default => Stance::Abstain,
        };

        $label = self::label($why->permission);

        return new self(
            verdict: $verdict,
            cause: $cause,
            summary: $cause->line([
                'permission' => $label ?? Line::of('filament-warden::ui.explain.no_permission'),
                'role' => self::label($why->role) ?? '',
            ]),
            permission: $label,
            role: self::label($why->role),
            // On a class check warden never tests a narrowed rule, and its
            // cause never says so: an owned rule reads as no match, a grant
            // with conditions as conditions not met.
            note: Narrowing::of($permission)->isNarrowed() && ! $entity instanceof Model
                ? Line::of('filament-warden::ui.probe.narrowed')
                : null,
            rule: self::matched($why->permission),
            via: self::via($authority, $why->role),
            until: self::until($authority, $why->permission, $why->role, $why->cause),
        );
    }

    /**
     * What the permission is about: a class, warden's wildcard, nothing at all,
     * or a morph alias that no longer resolves — which does not throw, it simply
     * stops matching, and that is worth saying rather than answering around.
     */
    private static function entity(Model $permission): string|false|null
    {
        $type = $permission->getAttribute('entity_type');

        if (! is_string($type)) {
            return null;
        }

        if ($type === '*') {
            return '*';
        }

        return Morph::model($type) ?? false;
    }

    /**
     * An answer warden was never asked for, because the question could not be
     * put. It says which, rather than reporting an abstention that would read as
     * a verdict.
     */
    private static function refused(string $reason): self
    {
        return new self(
            verdict: Stance::Abstain,
            cause: Cause::NotApplicable,
            summary: Line::of('filament-warden::ui.probe.'.$reason),
        );
    }

    private static function label(?Model $model): ?string
    {
        if (! $model instanceof Model) {
            return null;
        }

        $title = $model->getAttribute('title');

        if (is_string($title) && $title !== '') {
            return $title;
        }

        $name = $model->getAttribute('name');

        return is_string($name) ? $name : null;
    }

    /**
     * The rule the deciding row carries, read out as it will be evaluated.
     *
     * Off the row warden MATCHED and never off the row on screen. A condition is
     * a row of the catalogue, so a narrowed grant points at a twin — same name,
     * same entity, different `options` — and the twin is what answered. Printing
     * this screen's rule beside that row's verdict would be two facts about two
     * rows read as one, which is the shape of every wrong answer this card can
     * give.
     *
     * Null when warden hands back no row — `NoMatchingGrant` and `NotApplicable`
     * — or a row with no conditions: neither is a gap.
     */
    private static function matched(?Model $permission): ?string
    {
        if (! $permission instanceof Model) {
            return null;
        }

        $narrowing = Narrowing::of($permission);

        return $narrowing->rules === []
            ? null
            : $narrowing->preview(
                Line::of('filament-warden::ui.conditions.authority'),
                Line::of('filament-warden::ui.conditions.and'),
                Line::of('filament-warden::ui.conditions.or'),
            );
    }

    /**
     * How the account reaches the role, when a role is how it reached at all —
     * which only the two via-a-role causes carry.
     *
     * What it adds to the role's name is the RESTRICTION, and that is the half
     * worth a query: an assignment tied to a context is invisible to
     * `whereCan()`'s grant pass, so `Reach` counts only a lower bound for this
     * account. The unrestricted row wins when both exist, because it is the one
     * that answers without a context in front of it.
     *
     * And the restricted branch is only reachable with a RECORD in the probe:
     * `Explainer::source()` counts a restricted assignment only when the check
     * carries a model that belongs to the context, so a class check names no
     * role at all and lands on the null above.
     */
    private static function via(Model $authority, ?Model $role): ?string
    {
        if (! $role instanceof Model) {
            return null;
        }

        $name = self::label($role);

        if ($name === null) {
            return null; // @codeCoverageIgnore
        }

        $restricted = Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $role->getKey())
            ->where('entity_type', $authority->getMorphClass())
            ->where('entity_id', $authority->getKey())
            ->tap(Expiry::live(...))
            ->orderByRaw('case when restricted_to_type is null then 0 else 1 end')
            ->first();

        $type = $restricted?->getAttribute('restricted_to_type');

        if (! is_string($type) || $type === '') {
            return Line::of('filament-warden::ui.probe.via', ['role' => $name]);
        }

        // The alias and not the class: `Morph::model()` answers null for an
        // alias whose class is gone, and a restriction whose class went away is
        // still a restriction — saying its stored name is closer to the truth
        // than saying nothing.
        $class = Morph::model($type);

        return Line::of('filament-warden::ui.probe.via_restricted', [
            'role' => $name,
            'context' => $class === null ? $type : Str::headline(class_basename($class)),
        ]);
    }

    /**
     * When this answer stops being this answer.
     *
     * Read from the two pivots and never from the explanation, because warden
     * does not put it there: `AuthorizationExplanation` carries the deciding
     * permission and role and no date at all, since expiry belongs to the ROW
     * that points at one rather than to either of them.
     *
     * TWO rows can end this answer and the earlier of them is the horizon — the
     * grant that matched, and, where a role is how it was reached, the
     * assignment that reaches it. Which of the two is named, because they are
     * moved in different places: a grant date is set from the role's grid, an
     * assignment date from the account.
     *
     * Both reads keep the tenant scope, deliberately. `explain()` answered
     * through that same filter, so the deciding row is one the filter allowed;
     * reading wider could pick another tenant's row and print its date beside a
     * verdict it had no part in. And both filter on live rows, because a lapsed
     * twin of the same shape is a row warden already stopped reading.
     */
    private static function until(Model $authority, ?Model $permission, ?Model $role, WardenCause $cause): ?string
    {
        if (! $permission instanceof Model) {
            return null;
        }

        $grant = self::ends(
            Context::resolve()->grantClass()::query()
                ->where('permission_id', $permission->getKey())
                ->when(
                    $role instanceof Model,
                    static fn (Builder $query): Builder => $query
                        ->where('entity_type', $role?->getMorphClass())
                        ->where('entity_id', $role?->getKey()),
                    static fn (Builder $query): Builder => match ($cause) {
                        WardenCause::GrantedToEveryone, WardenCause::ForbiddenToEveryone => $query->whereNull('entity_id'),
                        default => $query
                            ->where('entity_type', $authority->getMorphClass())
                            ->where('entity_id', $authority->getKey()),
                    },
                ),
        );

        $assignment = $role instanceof Model
            ? self::ends(
                Context::resolve()->assignedRoleClass()::query()
                    ->where('role_id', $role->getKey())
                    ->where('entity_type', $authority->getMorphClass())
                    ->where('entity_id', $authority->getKey()),
            )
            : null;

        $soonest = match (true) {
            ! $grant instanceof CarbonImmutable => $assignment,
            ! $assignment instanceof CarbonImmutable => $grant,
            default => $grant->lessThanOrEqualTo($assignment) ? $grant : $assignment,
        };

        if (! $soonest instanceof CarbonImmutable) {
            return null;
        }

        return Line::of(
            'filament-warden::ui.probe.'.($soonest === $assignment && $grant !== $assignment ? 'until_assignment' : 'until_grant'),
            ['date' => $soonest->toDayDateTimeString(), 'human' => $soonest->diffForHumans()],
        );
    }

    /**
     * The soonest end date among live rows, or nothing when none of them ends.
     *
     * A row with no date outlives every row that has one, so `orderBy` alone
     * would answer `null` first on most engines and call an ending grant
     * endless. The null rows are dropped instead: "none of these ends" and "the
     * first one ends on" are the two answers, and a row without a date belongs
     * to the first.
     *
     * The row type is a template rather than `Model`: `Builder`'s own `TModel`
     * is not covariant, so a builder for warden's grant class is not a
     * `Builder<Model>` to the analyser, and both callers build exactly that.
     *
     * @template TRow of Model
     *
     * @param  Builder<TRow>  $query
     */
    private static function ends(Builder $query): ?CarbonImmutable
    {
        $row = $query
            ->tap(Expiry::live(...))
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->first();

        $ends = $row?->getAttribute('expires_at');

        return $ends instanceof DateTimeInterface ? CarbonImmutable::instance($ends) : null;
    }
}
