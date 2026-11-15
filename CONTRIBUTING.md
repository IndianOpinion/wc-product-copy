# Contributing

Thank you for helping. This file says how to set up, test and send a change.

## Setup

PHP 8.1 or later with `gd`, `mbstring` and `dom`, and Composer 2.

```sh
git clone https://github.com/IndianOpinion/wc-product-copy.git
cd wc-product-copy
composer install
```

No WordPress install is needed: the tests run against a small stub layer in
`tests/stubs/`.

## Checks

```sh
composer test     # PHPUnit
composer stan     # PHPStan, level 8
composer cs       # PHP_CodeSniffer, PSR-12
composer check    # all three
php examples/demo.php
```

All four must pass before a pull request is merged. CI runs them on PHP
8.1, 8.2 and 8.3.

## Style

- PSR-12, `declare(strict_types=1);` in every file, PHP 8.1 syntax only (no
  readonly classes, no DNF types, no `true` type, no constants in traits).
- Short sentences and plain words in docs and messages. British spelling.
- No em dashes anywhere. Use a comma, a colon or a full stop.
- Typed exceptions; never silence errors with `@`; no `eval`, no shell.
- Escape every value printed in admin. Nonce and capability checks on every
  form and button.
- Keep the plugin's promises: drafts only, never overwrite a person's text,
  only the store's own data, caps and the kill switch read afresh per job.

## Tests

Each behaviour in `docs/PRD.md` section 4 has a test named after its case.
A change in behaviour needs a test that fails without it. Tests never use
the network: `wp_remote_post()` in the stub layer throws.

## Pull requests

- One topic per pull request. Describe what changed and why, and how you
  tested it.
- Update `CHANGELOG.md` under "Unreleased", and `docs/ARCHITECTURE.md` when
  you take a design decision.
- A DCO-style sign-off is not required.
- Never include API keys, customer data or product text you cannot share.

## Security

Report security problems privately: see [SECURITY.md](SECURITY.md).
