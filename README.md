# Filament Warden

> Roles and permissions for [Filament](https://filamentphp.com), built on
> [elpandape/warden](https://github.com/elpandape/warden) — a permission grid derived from
> your policies, explicit denials, and conditional grants.

**Status: `0.1.0` — foundations.** The package installs and registers on a panel, and
registers no screens yet. See [CHANGELOG.md](CHANGELOG.md) for what each version adds.

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
