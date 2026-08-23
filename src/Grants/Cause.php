<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Checks\Explain\Cause as WardenCause;

/**
 * The eight reasons a check resolves the way it does, with a sentence a person
 * can read.
 *
 * Warden's own enum carries no labels and its `__toString()` is hard-coded
 * English, so every line here belongs to this package. The enum is mirrored
 * rather than used directly for one reason: these values end up in translation
 * keys and in the markup, and they should not move because a dependency renamed
 * a case.
 *
 * A caveat worth knowing when reading `ToEveryone` on screen: warden returns it
 * as an unconditional else, without confirming that a grant for everyone exists.
 * It is an inference, not a proof — which is why the inspector also prints the
 * raw case, so somebody can trace it when it does not add up.
 */
enum Cause: string
{
    case GrantedDirectly = 'granted-directly';

    case GrantedViaRole = 'granted-via-role';

    case GrantedToEveryone = 'granted-to-everyone';

    case ForbiddenDirectly = 'forbidden-directly';

    case ForbiddenViaRole = 'forbidden-via-role';

    case ForbiddenToEveryone = 'forbidden-to-everyone';

    case NoMatchingGrant = 'no-matching-grant';

    case NotApplicable = 'not-applicable';

    public static function of(WardenCause $cause): self
    {
        return self::from($cause->value);
    }

    /**
     * Not `Line::of()`, and the difference is the fallback: this one answers
     * with the case's OWN value rather than the translation key, which is a
     * word a person can read where `filament-warden::ui.explain.causes.tangled`
     * is not. `Line` cannot know it, so the policy lives with the enum.
     *
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    public function line(array $replace = []): string
    {
        $line = __('filament-warden::ui.explain.causes.'.$this->value, $replace);

        return is_string($line) ? $line : $this->value;
    }
}
