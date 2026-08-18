# Contributing

Thanks for considering a contribution.

## Ground rules

- **No local PHP or Composer needed.** Everything runs through Docker via `make`.
  If a command in this file is not a `make` target, it is a bug in this file.
- **The public API is not frozen yet.** Before `1.0.0` it may change between minor
  versions; anything that breaks a documented signature, config key or behavior still
  needs a good reason and a CHANGELOG entry.
- **Fail closed.** When a check cannot be decided, the answer is "no". Corrupt data,
  missing attributes and inexpressible conditions must never widen a grant.

## Getting started

```bash
make build      # build the dev image
make install    # composer install
make ci         # everything CI runs, in one command
```

Useful targets: `make test`, `make lint-fix`, `make rector-fix`, `make shell`.

## What a change needs before it merges

- `make ci` green: style (Pint), static analysis (PHPStan at max), refactoring
  (Rector dry-run), line coverage at 100 %, type coverage at 100 %, and profanity.
  These are gates, not goals.
- A test that fails without the change. For anything security-shaped (panel guards,
  permission catalogue derivation, config-driven limits), a test that proves it
  fails *closed*.
- Comments only where the code cannot speak for itself, in English.
- Package config keys are never read with a bare `config('filament-warden.…')`, because
  `mergeConfigFrom` is a no-op when the consuming application has cached its config —
  every key would read `null`. Read keys through an accessor that falls back to the
  packaged defaults, or, for a security flag, phrase it so that absent means on:
  `guard.pages` and `guard.widgets` default to `true`, so a bare read would disable the
  guard on exactly the machines set up for production.

## Commits

Conventional Commits in English, atomic:

```
feat: add service provider with its config

- what changed, and why it matters
- no filenames: the diff already lists them
```

## Reporting bugs

Include the Laravel, PHP and Filament versions, the database engine, and — most
useful of all — the output of `Warden::explain($user, 'permission', $entity)`, which
names the exact row and role behind a verdict.

Security issues: see [SECURITY.md](SECURITY.md). Do not open a public issue.
