# Changelog

All notable changes to Missivus for WordPress are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] — 2026-08-17

Initial release.

- Routes every `wp_mail()` call through Microsoft Graph `sendMail`, using OAuth2 client
  credentials and the `Mail.Send` application permission, through WordPress's own `pre_wp_mail`
  short-circuit (WP ≥ 5.7).
- The Graph transport is vendored **unchanged** from
  [missivus-matomo](https://github.com/Solvetus/missivus-matomo) v0.1.3: client secret and
  certificate authentication (PS256, with an RS256 escape hatch), token caching with a five-minute
  refresh margin, one retry on a 401, and secret redaction on every string that reaches a log.
- HTML and plaintext bodies, multiple recipients, Cc, Bcc, Reply-To, and attachments —
  automatically switching to the draft → upload session → send path above 3 MB so a big file never
  fails on size.
- Forced From: application-only Graph can only send as the nominated shared mailbox, so it always
  wins; a differing requested From is kept as Reply-To and warned about, never dropped silently.
- Settings → Missivus with write-only secrets, a `wp-config.php` constants tier
  (`MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_ID`, `MISSIVUS_AUTH_METHOD`, `MISSIVUS_CLIENT_SECRET`,
  `MISSIVUS_CERTIFICATE_PATH`, `MISSIVUS_CERTIFICATE_PASSPHRASE`, `MISSIVUS_SENDER`) that wins over
  the UI and is never written to the database, and a test-email button gated on the saved
  configuration being able to send.
- Optional fallback to the stock WordPress mailer, off by default; every failure is logged at error
  level and announced on `wp_mail_failed` with the exact (redacted) Microsoft error attached.
  Nothing is swallowed.
- i18n: PT-PT, FR, ES and IT translations shipped (AI-produced, human-reviewed).
- 46 PHPUnit tests against a mocked Graph; PHPCS with WordPress-Coding-Standards clean; the
  official Plugin Check passing; `php -l` clean under PHP 7.2, WordPress's floor.

[0.1.0]: https://github.com/Solvetus/missivus-wordpress/releases/tag/v0.1.0
