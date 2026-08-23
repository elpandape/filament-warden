<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Console;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\Warden\Context;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Command;

/**
 * What the panels declare, and which of it the store has a row for.
 *
 * Warden mints a permission on the first grant that names it, so a fresh
 * install's permissions screen lists almost nothing — which is correct and says
 * nothing about what exists. The roles screen draws the whole catalogue, but
 * only as a rendered panel, and not at all for an installation that switches
 * the roles resource off. This is the catalogue as data.
 *
 * Kept out of `filament-warden:audit` on purpose: that command's contract is
 * what is WRONG and nothing else, its `--check` is somebody's CI gate, and a
 * dump is neither.
 *
 * Labels are printed raw — enum values, fully qualified class names — so the
 * output can be grepped and needs no vocabulary of its own.
 */
final class CatalogCommand extends Command
{
    protected $signature = 'filament-warden:catalog {--panel= : Only this panel, by id}';

    protected $description = 'List the abilities each panel declares, and which of them the store has a row for';

    public function handle(): int
    {
        $id = $this->option('panel');

        if (is_string($id) && $id !== '') {
            $panel = Filament::getPanels()[$id] ?? null;

            if (! $panel instanceof Panel) {
                $this->components->error((string) __('filament-warden::ui.console.catalog.unknown_panel', ['panel' => $id]));

                return self::FAILURE;
            }

            $panels = [$panel];
        } else {
            $panels = array_values(Filament::getPanels());
        }

        $stored = $this->stored();

        $rows = array_map(
            static fn (Entry $entry): array => [
                $entry->name,
                $entry->entityType ?? '',
                $entry->model ?? '',
                $entry->scope->value,
                $entry->origin->value,
                ($stored[$entry->key()] ?? false) ? 'yes' : 'no',
            ],
            Catalog::union($panels)->entries,
        );

        $this->components->info((string) __('filament-warden::ui.console.catalog.heading', [
            'entries' => count($rows),
            'rows' => count($stored),
        ]));

        $this->table(
            ['name', 'entity', 'model', 'scope', 'origin', 'in store'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Which catalogue keys the store already has a row for.
     *
     * Keyed exactly as `Entry::key()` keys, which is the same shape
     * `Audit::orphans()` builds its own declaredness map with — two answers to
     * the same question that disagreed would be worse than one.
     *
     * Read across every tenant: the catalogue belongs to no tenant in
     * particular, so counting only the active one would report rows missing
     * that are merely elsewhere.
     *
     * @return array<string, true>
     */
    private function stored(): array
    {
        $rows = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScopes()
            ->get(['name', 'entity_type']);

        $stored = [];

        foreach ($rows as $row) {
            $name = $row->getAttribute('name');
            $type = $row->getAttribute('entity_type');

            if (is_string($name)) {
                $stored[$name.'|'.(is_string($type) ? $type : '')] = true;
            }
        }

        return $stored;
    }
}
