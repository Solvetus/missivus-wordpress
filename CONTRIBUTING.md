# Contributing

Pull requests are welcome — bug fixes, features, documentation, translations.

One boundary: the Microsoft Graph transport under `src/Vendor/Solvetus/Missivus/` is vendored
**unchanged** from [missivus-matomo](https://github.com/Solvetus/missivus-matomo). Fixes to it go
upstream there first, never here.

Non-English strings in this repository (`languages/*.po`) are AI-produced and
human-reviewed, not written by native speakers of every language. Corrections
are welcome and appreciated.

No CLA or DCO is required to contribute.

## Running the checks

The plugin itself has zero Composer dependencies; `composer.json` at the repo root is a
dev-only harness for tests and linting. On a fresh checkout:

```
composer install
composer test    # PHPUnit suite
composer lint    # WordPress Coding Standards (phpcs)
composer compat  # PHPCompatibilityWP at the plugin's PHP floor (7.2)
```
