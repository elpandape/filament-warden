<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Console;

use ElPandaPe\FilamentWarden\Catalog\Audit;
use Illuminate\Console\Command;

/**
 * What is wrong, and nothing else.
 *
 * It writes nothing. Deleting is `warden:clean`'s job, and putting deletion in a
 * command that runs on deploy turns a configuration mistake into lost data.
 *
 * With `--check` it answers 1, which is how a build goes red — and it is the only
 * way the boot guard reaches CI at all: `Panel::boot()` has one caller in the
 * whole of Filament, the HTTP middleware, so no artisan command ever starts a
 * panel.
 */
final class AuditCommand extends Command
{
    protected $signature = 'filament-warden:audit {--check : Exit with 1 when anything is found}';

    protected $description = 'Report screens nobody guards, resources with no policy, orphaned permissions and grants nothing declares';

    public function handle(): int
    {
        $audit = Audit::run();

        $this->report(__('filament-warden::ui.console.audit.open'), $audit->open);
        $this->report(__('filament-warden::ui.console.audit.unpoliced'), $audit->unpoliced);
        $this->report(__('filament-warden::ui.console.audit.orphans'), $audit->orphans);
        $this->report(__('filament-warden::ui.console.audit.strays'), $audit->strays);
        $this->report(__('filament-warden::ui.console.audit.drifted'), $audit->drifted);
        $this->report(__('filament-warden::ui.console.audit.unwalkable'), $audit->unwalkable);

        if ($audit->isClean()) {
            $this->components->info((string) __('filament-warden::ui.console.audit.clean'));

            return self::SUCCESS;
        }

        return (bool) $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $findings
     */
    private function report(mixed $heading, array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $this->components->warn(is_string($heading) ? $heading : '');

        $this->table([''], array_map(static fn (string $finding): array => [$finding], $findings));
    }
}
