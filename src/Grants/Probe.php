<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What the store answers for one account, asked out loud.
 *
 * `explain()` put on screen: pick an account, optionally a record, and warden
 * says the verdict and the cause. It is the only place in the panel where the
 * question is asked the way the application asks it — with a real authority and
 * a real row — rather than about a class with nothing in front of it.
 *
 * Which is also why it is worth having: a narrowed rule can never match a class
 * check, so the grid can only ever say "nothing matched" about one. Here it can
 * be proved either way.
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

        // Never `! allowed()`: forbidden and abstained are different answers.
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
                'permission' => $label ?? self::line('filament-warden::ui.explain.no_permission'),
                'role' => self::label($why->role) ?? '',
            ]),
            permission: $label,
            role: self::label($why->role),
            // The gap warden cannot report: it skips a candidate whose conditions
            // do not pass and answers "nothing matched", which reads exactly like
            // "there is no such rule".
            note: Narrowing::of($permission)->isNarrowed() && ! $entity instanceof Model
                ? self::line('filament-warden::ui.probe.narrowed')
                : null,
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

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($class, Model::class) ? $class : false;
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
            summary: self::line('filament-warden::ui.probe.'.$reason),
        );
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /**
     * The title if warden generated one, the name if it did not, and nothing at
     * all when there is no row.
     */
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
}
