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
 *
 * One of the seven lists is informational and never reaches the exit code:
 * permissions the catalogue declares that no grant points at. Turning a grid cell
 * off leaves exactly that row behind, so a build that went red on it would go red
 * on every save and stay red.
 */
final class AuditCommand extends Command
{
    protected $signature = 'filament-warden:audit {--check : Exit with 1 when an actionable finding is reported; the informational list never turns a build red}';

    protected $description = 'Report screens nobody guards, resources with no policy, unused permissions and grants nothing declares';

    public function handle(): int
    {
        $audit = Audit::run();

        $this->report(__('filament-warden::ui.console.audit.open'), $audit->open);
        $this->report(__('filament-warden::ui.console.audit.unpoliced'), $audit->unpoliced);
        $this->report(__('filament-warden::ui.console.audit.orphans'), $audit->orphans, red: false);
        $this->report(__('filament-warden::ui.console.audit.forgotten'), $audit->forgotten);
        $this->report(__('filament-warden::ui.console.audit.strays'), $audit->strays);
        $this->report(__('filament-warden::ui.console.audit.drifted'), $audit->drifted);
        $this->report(__('filament-warden::ui.console.audit.unwalkable'), $audit->unwalkable);

        if ($audit->isSilent()) {
            $this->components->info((string) __('filament-warden::ui.console.audit.clean'));
        }

        if ($audit->isClean()) {
            return self::SUCCESS;
        }

        return (bool) $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The informational list is told apart by its colour, not by its position: a
     * yellow heading over a list nothing can be done about is what teaches a reader
     * to stop reading the yellow ones.
     *
     * @param  array<int, string>  $findings
     */
    private function report(mixed $heading, array $findings, bool $red = true): void
    {
        if ($findings === []) {
            return;
        }

        $line = is_string($heading) ? $heading : '';

        if ($red) {
            $this->components->warn($line);
        } else {
            $this->components->info($line);
        }

        $this->table([''], array_map(static fn (string $finding): array => [$finding], $findings));
    }
}
