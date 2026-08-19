# Filament Warden

> Roles and permissions for [Filament](https://filamentphp.com), built on
> [elpandape/warden](https://github.com/elpandape/warden) — a permission grid derived from
> your policies, explicit denials, and conditional grants.

**Status: `0.2.0` — the catalogue and the lock.** The package derives the list of actions
from your policies and closes the doors Filament leaves open. No screens yet. See
[CHANGELOG.md](CHANGELOG.md) for what each version adds.

## Requirements

- PHP 8.5
- Laravel 13
- Filament 5.7
- elpandape/warden 1.0

## Installation

```bash
composer require elpandape/filament-warden
```

Register the plugin on the panel that should carry the roles and permissions screens:

```php
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(FilamentWardenPlugin::make());
}
```

Publish the configuration if you want to change what the screens let people do:

```bash
php artisan vendor:publish --tag=filament-warden-config
```

## Usage

### Write your policies on top of the base class

A policy declares the actions that exist for its model, and nothing else. The base class
brings none of its own, and resolves through warden's store rather than through the gate —
going through the gate would resolve this very policy and never finish the question.

```php
use App\Models\Order;
use App\Models\User;
use ElPandaPe\FilamentWarden\Policies\WardenPolicy;

final class OrderPolicy extends WardenPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'viewAny', Order::class);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, 'view', $order);
    }
}
```

Two methods, so two permissions. Nothing offers `restore` for a model that cannot be
restored.

### Close the door of the panel

Filament lets every authenticated user into a panel unless the account model says
otherwise. The permission is derived from the panel id — a panel called `admin` is opened
by `panel:admin` — so two panels are two doors.

```php
use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;
use Filament\Models\Contracts\FilamentUser;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
}
```

An installation that already stores another name maps it in the config instead of renaming
rows:

```php
'guard' => [
    'panel' => ['admin' => 'viewAdminPanel'],
],
```

### Close your pages and widgets

`Page::canAccess()` and `Widget::canView()` return `true` literally in Filament, and
`->strictAuthorization()` reaches neither — it only covers resources. Compose the guard on
every standalone page and every widget:

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesWidgetView;

final class Reports extends Page
{
    use AuthorizesPageAccess;
}

final class RevenueChart extends ChartWidget
{
    use AuthorizesWidgetView;
}
```

Pages of a resource need nothing: they are already covered by the resource's own
`canAccess()`, which asks the model's policy.

### Read the catalogue

```php
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use Filament\Facades\Filament;

$entries = Catalog::for(Filament::getPanel('admin'))->entries;
```

Every entry names an action, the entity it applies to, the scope it falls in, and where it
came from. A model with a policy but no resource is declared in `catalog.models`; a
permission that belongs to no model at all goes in `catalog.custom`, with its scope:

```php
'catalog' => [
    'models' => [\Laravel\Passkeys\Passkey::class],
    'custom' => ['export-reports' => 'read'],
],
```

### Replacing what this package registers

The policies for warden's `Role` and `Permission` are registered by this package, because
Laravel's policy guessing never looks in a vendor namespace it does not own. An application
that wants its own registers it in its own service provider, which boots after every
package provider and therefore wins.

## Why this package

There is already a well-known permissions plugin for Filament, and for most projects it is
the right answer. This one exists for what Warden does that others do not: explicit
denials that beat any grant, per-instance permissions, and conditions attached to a grant.

What it adds on top of a wall of checkboxes:

- **The catalogue is derived from your policies, and only from them.** A resource offers
  exactly the actions its policy declares. Delete a method and its switch disappears.
- **Permissions are stored against models**, not as strings: renaming a resource orphans
  nothing.
- **A denial is a state, not an absence.**
- **Every switch can say why it is where it is**, naming the row and the role that decided.

## Development

The host needs no PHP or Composer — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make test       # the test suite
make ci         # every gate CI runs
```

## Credits

- [Carlos Mayorga](https://carlosmayorga.me/)

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
