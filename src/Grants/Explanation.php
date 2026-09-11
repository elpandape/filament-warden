<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\Stance;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

/**
 * Why one cell is the way it is, in words.
 *
 * Three things it adds to warden's own explanation:
 *
 *   - It keeps "explicitly forbidden" apart from "warden abstains and your
 *     policies decide", reading the verdict and never `allowed()`, which folds
 *     the two together — and that distinction is what this panel exists for.
 *   - It says a rule is narrowed, and says WHY it could not have matched.
 *     `ConditionsNotMet` carries the rejected row, but on a role grid a cell is
 *     asked about a CLASS, and a class check evaluates no condition and
 *     resolves no ownership — "its conditions were not satisfied" is true and
 *     still not the reason. That is what the separate narrowed line says, and
 *     why it is not redundant with the cause.
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
        public ?string $until = null,
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
        ?CarbonImmutable $until = null,
    ): self {
        $why = Warden::explain($role, $entry->name, $entry->model);

        $cause = Cause::of($why->cause);

        $verdict = match (true) {
            $why->verdict->isGranted() => Stance::Granted,
            $why->verdict->isForbidden() => Stance::Forbidden,
            default => Stance::Abstain,
        };

        // Both are null in different causes, and not symmetrically. The role is
        // null in all but the two via-a-role causes. The permission is null in
        // `NoMatchingGrant` and `NotApplicable` and NOT in the third abstaining
        // cause: `ConditionsNotMet` hands back the very row whose conditions
        // rejected the check. And a role only carries name, title and scope —
        // reading anything else off it throws under strict mode.
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
            until: self::until($until),
        );
    }

    /**
     * The answer on a screen where the role does not exist yet.
     *
     * `explain()` reads the store, and on a create form there is nothing in it
     * to read: every cell abstains because none has been saved, not because
     * warden looked and found nothing. An empty array would not be silence —
     * `[]` is truthy in the browser — so this is a real answer.
     *
     * There is no cause, and none is invented: a borrowed one would be a false
     * trail, which is worse than an empty slot.
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
            'until' => $this->until,
        ];
    }

    /**
     * The date beside warden's cause, never instead of it.
     *
     * `Cause::Expired` does not exist: warden filters expiry in SQL, so a
     * lapsed grant comes back as `NoMatchingGrant` — byte for byte what a cell
     * nobody ever wrote answers. The cause is true and it is not the story, so
     * this sentence goes alongside it rather than replacing it, the same way the
     * narrowed line does.
     *
     * The date arrives already read: `DrawsThePermissionGrid` has it in the
     * `RoleState` it built for the grid, so the inspector costs no query for it.
     * Comparing it to now is one reading, made here, because whether a cell says
     * "expires" or "expired" is a display decision and nothing else on the
     * screen answers it.
     */
    private static function until(?CarbonImmutable $until): ?string
    {
        if (! $until instanceof CarbonImmutable) {
            return null;
        }

        $key = $until->lessThanOrEqualTo(CarbonImmutable::now()) ? 'expired' : 'expires';

        return self::line('filament-warden::ui.explain.'.$key, [
            'date' => $until->toDayDateTimeString(),
            'human' => $until->diffForHumans(),
        ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, array $replace = []): string
    {
        return Line::of($key, $replace);
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
}
