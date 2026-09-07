<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Console;

use ElPandaPe\FilamentWarden\Catalog\Audit;
use Filament\Facades\Filament;
use Filament\Panel;
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
 * Two of the lists are informational and never reach the exit code: permissions the
 * catalogue declares that no grant points at, and grants whose authority no longer
 * exists. Turning a grid cell off leaves exactly the first behind, so a build that
 * went red on it would go red on every save and stay red; and the second has no cure
 * inside this package at all.
 */
final class AuditCommand extends Command
{
    protected $signature = 'filament-warden:audit
        {--check : Exit with 1 when an actionable finding is reported; the informational lists never turn a build red}
        {--panel= : Only this panel, by id}';

    protected $description = 'Report screens nobody guards, resources with no policy, unused permissions and grants nothing declares';

    public function handle(): int
    {
        $panels = $this->panels();

        if ($panels === null) {
            return self::FAILURE;
        }

        $audit = Audit::of($panels);

        $this->report(__('filament-warden::ui.console.audit.misconfigured'), $audit->misconfigured);
        $this->report(__('filament-warden::ui.console.audit.unmigrated'), $audit->unmigrated);
        $this->report(__('filament-warden::ui.console.audit.open'), $audit->open);
        $this->report(__('filament-warden::ui.console.audit.unpoliced'), $audit->unpoliced);
        $this->report(__('filament-warden::ui.console.audit.orphans'), $audit->orphans, red: false);
        $this->report(__('filament-warden::ui.console.audit.forgotten'), $audit->forgotten);
        $this->report(__('filament-warden::ui.console.audit.strays'), $audit->strays);
        $this->report(__('filament-warden::ui.console.audit.drifted'), $audit->drifted);
        $this->report(__('filament-warden::ui.console.audit.unwalkable'), $audit->unwalkable, red: false);
        $this->report(__('filament-warden::ui.console.audit.unkeyable'), $audit->unkeyable);
        $this->report(__('filament-warden::ui.console.audit.unownable'), $audit->unownable);
        $this->report(__('filament-warden::ui.console.audit.unsatisfiable'), $audit->unsatisfiable);
        $this->report(__('filament-warden::ui.console.audit.stranded'), $audit->stranded, red: false);
        $this->report(__('filament-warden::ui.console.audit.dormant'), $audit->dormant, red: false);

        if ($audit->isSilent()) {
            $this->components->info((string) __('filament-warden::ui.console.audit.clean'));
        }

        if ($audit->isClean()) {
            return self::SUCCESS;
        }

        return (bool) $this->option('check') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The panels to audit, or null when the name given matches none.
     *
     * The same shape `filament-warden:catalog` carries, and it
     * gets its own sentence rather than borrowing that command's: the two are
     * separate keys because a published translation may have one and not the
     * other, and a reader chasing "no panel with that id" should land on the
     * command that said it.
     *
     * @return list<Panel>|null
     */
    private function panels(): ?array
    {
        $id = $this->option('panel');

        if (! is_string($id) || $id === '') {
            return array_values(Filament::getPanels());
        }

        $panel = Filament::getPanels()[$id] ?? null;

        if (! $panel instanceof Panel) {
            $this->components->error((string) __('filament-warden::ui.console.audit.unknown_panel', ['panel' => $id]));

            return null;
        }

        return [$panel];
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
