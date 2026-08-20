# Filament Warden

> Roles and permissions for [Filament](https://filamentphp.com), built on
> [elpandape/warden](https://github.com/elpandape/warden) — a permission grid derived from
> your policies, explicit denials, and conditional grants.

**Status: `0.9.0` — the reach on screen.** A roles screen with the permission grid inside
it, derived from your policies; an inspector that says why each cell is the way it is; a builder
that narrows a grant to the rows it should reach; a permissions screen that says where every
row of the catalogue came from; a field that hands roles to an account; and a guard that stops a panel starting with a screen
nobody decides. See [CHANGELOG.md](CHANGELOG.md) for what each version adds.

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

Publish the package's assets so the grid arrives with its styles. This is a plain file
copy, not a build step — there is no Tailwind and no bundler in this package — and it has
to run again on every deploy, so applications usually hang it off composer's
`post-autoload-dump`:

```bash
php artisan filament:assets
```

Until it runs, the panel asks for a stylesheet that answers 404 and the grid renders
unstyled.

Publish the configuration if you want to change what the screens let people do:

```bash
php artisan vendor:publish --tag=filament-warden-config
```

Publish the views if you want to rewrite the grid's markup:

```bash
php artisan vendor:publish --tag=filament-warden-views
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

### The grid

Registering the plugin puts a roles screen on the panel. Inside its form, the grid draws one
row per entity and one column per action the policy declares — grouped by scope, so listing
records does not look like deleting them for good — plus a wildcard column.

A cell cycles: click to go from abstaining to granted to forbidden, and hold shift to walk
the cycle backwards and reach a denial in one step. An action no policy declares is a dot
and not a control, because nobody could grant it.

Nothing is written until you save, and the save is a diff — only the cells that changed
reach the store.

Click a cell and the inspector beside the grid says why it is the way it is: the cause, the
permission that decided it, and the role it came through. It tells an explicit denial apart
from warden abstaining — two different answers that most stores cannot distinguish. It is
asked and never volunteered, because one explanation is three to seven queries and a grid
full of them would be hundreds.

The answer is about what is stored, so if you have cycled a cell and not saved it, the
panel says that too rather than appearing to contradict the screen.

A role can also be read without being changed: `view` and `update` are separate permissions,
and the read-only screen draws the same grid and answers the same questions.

### How far a rule reaches

Under the explanation, a cell that grants or forbids something says how far it reaches, in
three alternatives: **every row**, **only what it owns**, or **with these conditions**.
Ownership is offered only when it can actually resolve — warden's default attribute is the
literal `user_id` and cannot be turned off, so a model whose table has no such column is
told why the alternative is closed to it.

The conditions are a flat list. Each line compares a column of the row against a value or
against a column of the account being checked, with one of warden's six operators, and each
line carries the joiner that ties it to the one above. `and` binds tighter than `or`, the
way it does in SQL, so the builder boxes the lines a group contains and prints the rule as
it will be read:

```text
name = editor or (scope >= 2 and title = account.name)
```

A cell marked amber is narrowed: with conditions, the grant only answers with a record in
front of it, and a class check — the one a listing makes when it asks `viewAny` — fails
closed. A cell marked red is one this screen can read and cannot draw: conditions that do
not deserialize, or two rules stored for the same action. Those are shown, explained, and
never written over.

Every write goes through warden's fluent API, in one transaction, and clears the cell in
both of its shapes first: `to()` and `toOwn()` are disjoint revokes, and editing a condition
any other way leaves the previous twin's grant standing and the old rule still authorizing.

### The permissions screen

Registering the plugin also puts a permissions screen on the panel, and with the shipped defaults
it is a reading surface: nothing can be created, only loose permissions can be edited, and only
orphans can be deleted. Opening any of that up is one line of `permissions.*` config; closing it
again afterwards means cleaning up whatever was created meanwhile.

What it shows that warden cannot say for itself:

- **Where each row came from** — derived from a policy, loose, the wildcard, or *nothing declares
  it*. The last one is a permission no code asks for any more: a renamed policy method, a typo in
  a seeder, a screen that was deleted.
- **How far it reaches** — every row, only what it owns, or with conditions. A permission carrying
  conditions is a twin of the plain row, and without this column the two are indistinguishable.
- **Who holds it**, as counts, with explicit denials counted apart.
- **A test bench**: pick an account and, if the permission has a model, the key of a row, and
  warden answers with its verdict and its cause. It is the only place in the panel where the
  question is asked the way your application asks it.

Two things worth knowing before you edit anything here. Editing the conditions of a permission
changes the rule for **everyone holding that row** — a constrained permission is a shared twin,
which is correct for a catalogue and is said on screen. And deleting a permission takes its grants
with it through a foreign key, below Eloquent and with no event of its own, so the confirmation
names who loses it: afterwards there is no trace.

### Handing roles to an account

Add one line to your own account resource's form:

```php
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

RoleAssignment::make('roles')->columnSpanFull(),
```

It has to be your line because Filament reads a resource's relation managers and fields from the
resource class itself, and a package cannot write to `getRelations()` from outside — Filament's own
generator says the same thing when it scaffolds one.

**Do not reach for `CheckboxList::make('roles')->relationship(...)` instead.** It looks equivalent
and it is not: `->relationship()` saves through `sync()`, and `sync()`, `attach()` and `detach()`
all skip warden's cache invalidation. A role handed out that way goes on answering the old way,
silently and with no expiry. This field writes through warden's own API, so the store answers for a
role the moment it is given.

You may hand out a role if you may edit it — `update` over that role. A role you cannot edit is
shown, locked, and says why; so is a role this account holds *in a context*, because taking that
back would take every context with it.

### The way back

```bash
php artisan filament-warden:assign super-admin "App\Models\User:1"
```

For whoever locks themselves out. It refuses a role that does not exist rather than creating one —
assigning by name would have minted it, silently and with a generated title — and it only hands
roles out: you lock yourself out by not having a role, never by having one.

### The guard

**From `0.8.0`, a panel does not start with a page or a widget that does not decide who gets in.**
Filament's own `Page::canAccess()` and `Widget::canView()` return `true` literally, and
`->strictAuthorization()` reaches neither — so a screen registered without a decision is open to
anybody who can reach the panel, in silence and with nothing to read.

The exception names the screens. Give each one a decision:

```php
use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;

class Reports extends Page
{
    use AuthorizesPageAccess;
}
```

Filament's own screens are its own business; this is about the ones you or a package registered.
Turn either half off with `guard.pages` / `guard.widgets`.

### The audit

```bash
php artisan filament-warden:audit
php artisan filament-warden:audit --check   # exits 1 when anything is found
```

It writes nothing, and reports six things:

- **screens nobody guards** — the same finding the guard throws on, which is how it reaches CI at
  all: no artisan command ever starts a panel;
- **resources whose model has no policy** — the case Filament fails open on, told apart from a
  policy that declares nothing and from a resource pointing at a class that does not exist;
- **permissions no grant points at** — `warden:clean` is what removes them;
- **grants for actions nothing declares any more** — a renamed policy method, a typo in a seeder, a
  screen that was deleted: the silent mistake warden has no way to detect;
- **whole entity types nothing declares** — a morph alias that moved, reported apart because the fix
  is the opposite one;
- **models only a relation manager reaches**, with the `catalog.models` line that settles it.

### How far a permission reaches

The test bench also answers *over how many rows* a permission falls, for the account being probed.
It is opt-in on your side, one line on the model:

```php
use ElPandaPe\Warden\Concerns\QueriesByPermission;

class Document extends Model
{
    use QueriesByPermission;
}
```

Without it the screen says so and names the model, rather than printing a number: warden's
`whereCan()` does not fail on a model that never composed the trait — Eloquent turns the call into a
dynamic `where "can" = ?` and answers zero rows in silence.

**The number is a lower bound, not a fact**, and the screen says when. A role assigned in a context
is excluded from `whereCan()`'s grant pass, so the panel itself will answer `true` for rows the query
cannot see. `whereCan()` also never consults the Gate or a policy.

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
