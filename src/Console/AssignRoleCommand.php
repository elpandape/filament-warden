<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Console;

use ElPandaPe\Warden\Exceptions\RoleDoesNotExist;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * The way back.
 *
 * Somebody will lock themselves out — take the last role that could hand roles
 * out, or close a panel behind a permission nobody holds — and the screens are
 * exactly what they can no longer reach. This is the door that does not need
 * one.
 *
 * It only hands roles OUT. You lock yourself out by not having a role, never by
 * having one, and a console command that takes access away turns a typo into
 * lost access — the same reason the audit command of a later version does not
 * delete.
 */
final class AssignRoleCommand extends Command
{
    protected $signature = 'filament-warden:assign
        {role : The role to hand out}
        {authority : The account, as Class:id (e.g. "App\\Models\\User:1")}';

    protected $description = 'Hand a role to an account from the console, for whoever locked themselves out';

    public function handle(): int
    {
        $name = $this->text($this->argument('role'));
        $reference = $this->text($this->argument('authority'));

        // `Warden::assign('typo')` CREATES the role it cannot find, silently and
        // with a generated title. On a screen that is unreachable; here it is one
        // keystroke away, and this is the way back — it has to fail rather than
        // invent something nobody asked for.
        try {
            $role = Warden::findRole($name);
        } catch (RoleDoesNotExist) {
            $this->components->error((string) __('filament-warden::ui.console.assign.missing_role', ['role' => $name]));

            return self::FAILURE;
        }

        $authority = $this->authority($reference);

        if (! $authority instanceof Model) {
            $this->components->error((string) __('filament-warden::ui.console.assign.missing_authority', ['authority' => $reference]));

            return self::FAILURE;
        }

        Warden::assign($role)->to($authority);

        $this->components->info((string) __('filament-warden::ui.console.assign.done', [
            'role' => $name,
            'authority' => $reference,
        ]));

        return self::SUCCESS;
    }

    /**
     * The same `Class:id` shape `warden:show` reads, so the two commands are
     * used the same way.
     */
    private function authority(string $reference): ?Model
    {
        [$class, $key] = array_pad(explode(':', $reference, 2), 2, null);

        if (! is_string($class) || ! is_string($key) || $key === '' || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($key);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
