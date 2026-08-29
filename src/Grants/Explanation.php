<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

/**
 * Why one cell is the way it is, in words.
 *
 * Three things it does that warden's own explanation cannot:
 *
 *   - It tells "explicitly forbidden" apart from "warden abstains and your
 *     policies decide". `allowed()` is the only helper warden ships and it
 *     conflates them, which is exactly the distinction this panel exists for.
 *   - It says a rule is narrowed, and says WHY it could not have matched.
 *     Warden 2.0 answers `ConditionsNotMet` with the rejected row attached, so
 *     it no longer reads as "there is no such grant", and warden's own sentence
 *     stopped naming a record in its `2.0.1` after this package reported that it
 *     did. What stays this package's to draw is the half underneath: on a role
 *     grid a cell is asked about a CLASS, so a narrowed rule fails closed before
 *     a single condition is evaluated — "its conditions were not satisfied" is
 *     true and still not the reason. That is what the separate narrowed line
 *     says, and why it is not redundant with the cause.
 *   - It says when the screen and the store disagree, because the answer is
 *     always about what is stored and the person may have cycled the cell.
 */
final readonly class Explanation
{
    public function __construct(
        public Stance $verdict,
        public ?Cause $cause,
        public string $summary,
        public ?string $permission = null,
        public ?string $role = null,
        public ?string $narrowed = null,
        public ?string $pending = null,
    ) {}

    /**
     * @param  array<string, array<string, bool>>  $narrowed  the role's narrowed cells
     */
    public static function of(
        Model $role,
        Entry $entry,
        string $rowKey,
        string $action,
        array $narrowed = [],
        ?Stance $onScreen = null,
        ?Stance $stored = null,
    ): self {
        $why = Warden::explain($role, $entry->name, $entry->model);

        $cause = Cause::of($why->cause);

        // Never `! allowed()`: forbidden and abstained are different answers, and
        // telling them apart is the whole point of the panel.
        $verdict = match (true) {
            $why->verdict->isGranted() => Stance::Granted,
            $why->verdict->isForbidden() => Stance::Forbidden,
            default => Stance::Abstain,
        };

        // Both are null in different causes, and not symmetrically. The role is
        // null in seven of the nine — only the two via-a-role causes carry one.
        // The permission is null in `NoMatchingGrant` and `NotApplicable`, and
        // since warden 2.0 it is NOT null in the third abstaining cause:
        // `ConditionsNotMet` hands back the very row whose conditions rejected
        // the check. And a role only carries name, title and scope — reading
        // anything else off it throws under strict mode.
        $permission = self::label($why->permission);
        $roleName = self::label($why->role);

        return new self(
            verdict: $verdict,
            cause: $cause,
            summary: $cause->line([
                'permission' => $permission ?? self::line('filament-warden::ui.explain.no_permission'),
                'role' => $roleName ?? '',
            ]),
            permission: $permission,
            role: $roleName,
            narrowed: ($narrowed[$rowKey][$action] ?? false)
                ? self::line('filament-warden::ui.explain.narrowed')
                : null,
            pending: $onScreen instanceof Stance && $onScreen !== $stored
                ? self::line('filament-warden::ui.explain.pending', [
                    'stance' => self::line('filament-warden::ui.stances.'.$onScreen->value),
                ])
                : null,
        );
    }

    /**
     * The answer on a screen where the role does not exist yet.
     *
     * `explain()` reads the store, and on a create form there is nothing in it
     * to read: every cell abstains because none has been saved, not because
     * warden looked and found nothing. Answering `[]` here was not silence —
     * `[]` is truthy in the browser, and the template drew its verdict box with
     * three undefined values in it, on the first screen a new admin opens.
     *
     * There is no cause, and none is invented. The raw case is printed beside
     * the sentence so somebody can trace a verdict that does not add up; a
     * borrowed one would be a false trail, which is worse than an empty slot.
     */
    public static function unsaved(): self
    {
        return new self(
            verdict: Stance::Abstain,
            cause: null,
            summary: self::line('filament-warden::ui.explain.unsaved'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toPayload(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'cause' => $this->cause?->value,
            'summary' => $this->summary,
            'permission' => $this->permission,
            'role' => $this->role,
            'narrowed' => $this->narrowed,
            'pending' => $this->pending,
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        return Line::of($key, $replace);
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
