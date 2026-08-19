<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

/**
 * Registered as a page and answering none of a page's questions.
 *
 * A screen with no `canAccess()` at all decides nothing, which is the same
 * answer as one that decides `true` — and the guard has to reach that conclusion
 * instead of dying on the reflection.
 */
final class NotAPage {}
