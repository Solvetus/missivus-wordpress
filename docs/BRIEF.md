# Missivus for WordPress — Build Brief

The spec. `/goal` conditions reference this file. Read it fully first, then read the sibling repo
`/Users/rds/Projects/Solvetus/missivus-matomo` end to end (PLAN.md, docs/BRIEF.md, docs/index.md,
docs/faq.md, docs/SECURITY.md, the transport class and its tests). Missivus for WordPress is the
same product on a second platform: **same Graph transport class vendored unchanged, same
decisions, same documentation voice.**

## What it is

A free GPLv3 WordPress plugin (Solvetus Labs, family site https://missivus.com) that sends all
`wp_mail()` email through Microsoft Graph using **application permissions and a shared mailbox**
— client credentials, `Mail.Send` application permission, Exchange application access policy
scoped to ONE shared mailbox; no user login, no delegated OAuth, no paid extension, nothing to
click. Differentiator vs Post SMTP's Office 365 mailer and similar: those are paid and use
delegated auth (mail goes out as a person, breaks when they leave). Business model: plugin free,
Solvetus sells installation and support.

## Repo

`Solvetus/missivus-wordpress` (public GitHub, currently empty). Local folder is this one. Plugin
slug `missivus`, main file `missivus.php`, text domain `missivus`, namespace `Missivus\`.
Initialise git here, remote `git@github.com:Solvetus/missivus-wordpress.git`, branch `main`.
Commit identity comes from the `~/.gitconfig` includeIf for `/Users/rds/Projects/Solvetus/`.

## Read first (WordPress side)

`wp_mail()` in `wp-includes/pluggable.php`; the `pre_wp_mail` filter (short-circuit, WP ≥ 5.7)
— use it as the primary seam; `wp_mail_failed` action for failures; `phpmailer_init` only if
needed for edge cases; the WordPress Plugin Handbook (plugin basics, settings API, security —
nonces/capabilities/escaping/sanitising, internationalisation, plugin directory guidelines and
`readme.txt` format, "Plugin Check" tool); WP-CLI for local verification.

## Design goals (decided — same as Matomo, do not re-open)

- Seam: `pre_wp_mail` filter. When Missivus is enabled and configured, build the message from
  the `wp_mail` args (to/subject/message/headers/attachments — parse headers for From name,
  Reply-To, Cc, Bcc, Content-Type; support HTML and plaintext), send via Graph, fire
  `wp_mail_succeeded`-style action for hooks, return `true`. On failure: `wp_mail_failed` action
  with a `WP_Error` carrying the Graph error body, error-level log via `error_log` (never
  swallowed), optional fallback to WordPress's own mailer (default OFF) by returning `null`.
- Auth: OAuth2 client credentials; client secret AND certificate; UI default client secret;
  token cached in a transient with safety margin.
- Forced From: application-only Graph sends as `/users/{sender}`. Force From = configured
  sender mailbox; log a warning when the original From differed. Docs tell users to set the
  sender as the WordPress admin email or accept the override.
- Attachments: inline `fileAttachment` up to Graph's ceiling (~3 MB, verify); above that,
  create-draft → uploadSession → send. Both paths tested. Mail.ReadWrite needed only for the
  large path — documented.
- Settings: Settings → Missivus (capability `manage_options`). Fields: enable, Directory
  (tenant) ID, Application (client) ID, auth method, client secret (write-only, never redisplayed,
  stored in `wp_options`), certificate path + passphrase, sender mailbox, save-to-Sent toggle,
  fallback toggle, "Send test email" (admin-post or REST with nonce + capability check; result
  shown inline with the Microsoft error body on failure). Every value overridable by constants in
  `wp-config.php` (`MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_ID`, `MISSIVUS_CLIENT_SECRET`,
  `MISSIVUS_CERTIFICATE_PATH`, `MISSIVUS_CERTIFICATE_PASSPHRASE`, `MISSIVUS_SENDER`,
  `MISSIVUS_AUTH_METHOD`); a constant wins over the option and the UI shows "set in
  wp-config.php" instead of the value; a constant-defined secret is never written to the DB.
- Test-email button gates on saved+enabled config, same UX rule as the Matomo v0.1.1 fix.
- Portability boundary: vendor the transport class from `missivus-matomo` **unchanged** into
  `src/Vendor/` (or `lib/`) with its HTTP-adapter and token-cache interfaces; the WordPress
  adapters wrap `wp_remote_post` and transients. If the class needs any change to work here,
  stop and say so — the fix goes upstream in missivus-matomo first.
- Zero third-party runtime dependencies. No Composer autoload in the shipped zip (own PSR-4
  loader or classmap). PHP floor: match WordPress's current minimum; no syntax above it in the
  vendored class.
- Multisite: works per-site; network-activation supported without special UI in v1.
- Security: capability checks on every admin action, nonces, sanitise on input, escape on
  output, secrets never in logs/HTML/JS/REST responses; `docs/SECURITY.md` mirrors the Matomo one
  plus a WordPress section.
- Privacy/plugin-directory rule: the plugin talks to `login.microsoftonline.com` and
  `graph.microsoft.com` — state this plainly in `readme.txt` (Plugin Directory requires
  disclosure of external services) and in the settings page.
- i18n: text domain `missivus`, all strings translatable, `languages/missivus.pot` generated;
  EN authored; PT-PT/FR/ES/IT `.po` translations by you (AI-produced, human-reviewed line in
  readme), compiled `.mo`.
- Tests: PHPUnit with a mocked Graph endpoint (Brain\Monkey or WP_Mock acceptable as dev-only)
  covering: send happy path, header parsing (from/reply-to/cc/bcc/content-type), HTML vs plain,
  attachments inline and large, forced-From warning, fallback OFF/ON, constant overrides,
  secret redaction, capability/nonce on the test endpoint. Also PHPCS with
  WordPress-Coding-Standards and the official Plugin Check tool passing.
- Docs: `readme.txt` (WordPress format: short description ≤150 chars, Description, Installation,
  FAQ, Screenshots section listing files the owner will supply in `assets/`, Changelog),
  `README.md` (GitHub: opens with the mark from missivus-www `public/brand/missivus-mark.svg`,
  copied into `.github/`), `docs/INSTALL.md` (the same Entra + Exchange Online guide as
  missivus-matomo `docs/index.md`, adapted; PowerShell blocks identical), `docs/faq.md`,
  `docs/SECURITY.md`, `CONTRIBUTING.md`, `LICENSE` (GPLv3), `CHANGELOG.md`, `.github/social-preview.png`
  in the same style as the sibling repos (mark + lowercase wordmark on paper `#faf8f3`,
  "for WordPress").
- Build: `tools/build-zip.sh` (allowlist) → `dist/missivus-<version>.zip` with `missivus/` as the
  top-level folder, no tests/tools/docs-internal.
- Do NOT submit to wordpress.org — the owner does that. Do NOT touch any live site.
- Version 0.1.0.

## House rules

Secrets: never invent, print or commit. Latest versions. Small logical commits. One tasteful
line pointing to Solvetus for installation and support. Vault log line at the end to
`/Users/rds/Library/Mobile Documents/iCloud~md~obsidian/Documents/Omni/30_Projects/Missivus/Missivus.md`.
