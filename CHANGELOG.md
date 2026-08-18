# Changelog

All notable changes to Missivus for WordPress are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] — 2026-08-18

### Security

- **Endpoint override URLs are no longer repeated back into errors, the log, or the admin notice.**
  `Endpoint::normalise()` refused an unsafe `MISSIVUS_GRAPH_BASE_URL` / `MISSIVUS_LOGIN_BASE_URL`
  correctly, but ended its message with the rejected value verbatim — and that message was then
  logged by `Mailer`, attached to `wp_mail_failed`, and shown by the test-email screen with no final
  redaction pass. A base URL carrying credentials (`https://user:password@host`) or a token
  (`?access_token=…`) could therefore reach the PHP error log and the WordPress admin. Fixed in
  three independent layers:
  - `Endpoint` builds every message from scheme, host, port and path only — userinfo, query string
    and fragment are never assembled into a message at all — and reports a value it cannot parse, or
    a host name it cannot accept, by reason rather than by value.
  - `Redactor` gained URL patterns: credentials inside a URL, any `name=value` on the new
    `Redactor::SECRET_PARAMS` list (`access_token`, `client_secret`, `code`, `password`,
    `signature`, `sas`, …), and URL fragments. An ordinary mailbox address is deliberately left
    alone.
  - `Mailer::redact()` is now the single final pass on every string the mailer logs, hands to
    `wp_mail_failed`, or throws, and `Admin\TestEmail` applies the same pass to the admin notice.

  Reported against the Matomo plugin by [@textagroup](https://github.com/textagroup) (Kirk Mayo) —
  [missivus-matomo#1](https://github.com/Solvetus/missivus-matomo/issues/1). Thank you. Written up
  as finding 3a in [docs/SECURITY.md](docs/SECURITY.md).

### Changed

- The vendored transport under `src/Vendor/Solvetus/Missivus/` is resynced from
  [missivus-matomo](https://github.com/Solvetus/missivus-matomo) **v0.1.4**, and remains
  byte-for-byte identical to it.

### Added

- `tests/Unit/EndpointRedactionTest.php` — eleven tests covering URLs with userinfo, with
  `access_token` / `client_secret` / `code` query parameters, and with fragments, asserted absent
  from exception messages, from the log, from the `wp_mail_failed` payload, and from the admin
  notice. Two of them define a poisoned base-URL constant in their own PHP process and drive the
  real failure path. 57 tests in total, all passing; PHPCS clean.

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

[0.1.1]: https://github.com/Solvetus/missivus-wordpress/releases/tag/v0.1.1
[0.1.0]: https://github.com/Solvetus/missivus-wordpress/releases/tag/v0.1.0
