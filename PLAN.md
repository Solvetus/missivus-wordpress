# Missivus for WordPress — PLAN

The architecture record for `Solvetus/missivus-wordpress` v0.1.0, written before the first line of
plugin code, per [docs/BRIEF.md](docs/BRIEF.md). The sibling repository
`Solvetus/missivus-matomo` is the reference implementation: same product, same decisions, same
documentation voice, and — the hard rule — **the same Graph transport, vendored unchanged**.

---

## 1. What this is

A free GPLv3 WordPress plugin that routes every `wp_mail()` call through the Microsoft Graph API
using **OAuth2 client credentials** and the **Mail.Send application permission**, sending as one
shared mailbox scoped by an Exchange application access policy. No SMTP, no delegated login, no
licensed user, no paid tier. Slug `missivus`, main file `missivus.php`, text domain `missivus`,
plugin namespace `Missivus\`, vendored transport namespace `Solvetus\Missivus\`.

## 2. Portability boundary

Everything under `src/Vendor/Solvetus/Missivus/` is copied byte-for-byte from
`missivus-matomo/libs/Solvetus/Missivus/`. The release process must show
`diff -r` between the two trees producing no output. If the transport needs any change to work
under WordPress, **the build stops and the change goes upstream to missivus-matomo first** — there
is no WordPress fork of the transport, ever.

What the transport gives us, and what it expects (verified by reading the vendored tree end to end):

| Vendored class | Role |
| --- | --- |
| `GraphMailer` | The two send paths: inline `sendMail`, and draft → uploadSession → send for large attachments. Throws `GraphException` on any failure; never returns false. |
| `Message` | Transport-neutral email: from/to/cc/bcc/reply-to, subject, HTML and text bodies, attachments. `addCc()` exists precisely for this plugin — `wp_mail` supports Cc where `Piwik\Mail` did not. |
| `Attachment` | Name + raw bytes + MIME + optional contentId (inline/CID). |
| `Auth\Credentials` | Immutable credential holder; `__toString`/`__debugInfo` neutered so traces cannot spill a secret. |
| `Auth\TokenProvider` | Client-credentials tokens, cached with a 300 s safety margin, one retry after `invalidate()`. |
| `Auth\ClientAssertion` | PS256 (default) / RS256 signed JWT for certificate auth. |
| `Endpoint` | Refuses any base URL that is not a bare https origin — the anti-exfiltration lock. |
| `Redactor` | Blanks known secret literals and secret-shaped values from every string that reaches a log or an exception. |
| `Contract\HttpClientInterface` | Two methods (`post`, `put`). WordPress satisfies it with `wp_remote_request`. |
| `Contract\TokenCacheInterface` | Three methods (`get`, `set`, `delete`). WordPress satisfies it with transients. |
| `Contract\LoggerInterface` + `NullLogger` | PSR-3-shaped subset. WordPress satisfies it with `error_log`. |
| `Exception\GraphException` | Carries HTTP status and the redacted response body. |

The transport's PHP floor: no syntax above PHP 7.1 (`?Type` nullable hints are the newest thing in
it), `array()` notation throughout, no typed properties. WordPress's own floor is PHP 7.2.24, so
the vendored code clears it with room to spare. All plugin-side code is written to the same 7.2
floor and proven with `php -l` under a PHP 7.2 container before release.

## 3. Repository layout

```
missivus.php                  Plugin header + bootstrap: PSR-4 autoloader, Plugin::boot()
uninstall.php                 Deletes the option and token transients on uninstall
src/
  Plugin.php                  Wires all hooks; the only file that talks to add_action/add_filter
  Autoloader.php              Own PSR-4 loader: Missivus\ → src/, Solvetus\Missivus\ → src/Vendor/Solvetus/Missivus/
  Settings.php                The effective configuration: constants > option; sanitisation; problem reporting
  Mailer.php                  The pre_wp_mail handler: WpMail args → Message → GraphMailer → true/false/null
  WpMailParser.php            wp_mail argument parsing: recipients, headers, attachments → Message parts
  Adapter/HttpClient.php      Contract\HttpClientInterface over wp_remote_request
  Adapter/TokenCache.php      Contract\TokenCacheInterface over transients
  Adapter/Logger.php          Contract\LoggerInterface over error_log
  Admin/SettingsPage.php      Settings → Missivus: Settings API sections/fields, disclosure, test-email form
  Admin/TestEmail.php         admin-post handler: nonce + manage_options + is_email, sends without fallback
  Vendor/Solvetus/Missivus/   The transport, vendored unchanged (see §2)
languages/                    missivus.pot + pt_PT / fr_FR / es_ES / it_IT .po and .mo
docs/                         BRIEF.md (the spec), INSTALL.md, faq.md, SECURITY.md
tests/                        PHPUnit suite + WordPress function stubs + doubles (dev-only, not shipped)
tools/build-zip.sh            Allowlist build → dist/missivus-<version>.zip
readme.txt                    WordPress.org format
README.md                     GitHub page, opens with the mark
.github/                      mark.svg, social-preview.png
```

Zero third-party runtime dependencies. No Composer autoloader ships in the zip — `src/Autoloader.php`
is a dozen lines of `spl_autoload_register`, the same shape as `libs/autoload.php` in the Matomo
plugin. Composer appears only in `composer.json` as a dev harness for PHPCS/WPCS, and `tests/` run
under a PHPUnit phar with hand-rolled WordPress stubs — loading no WordPress at all, exactly as the
Matomo suite loads no Matomo.

## 4. The seam: `pre_wp_mail`

`wp_mail()` (wp-includes/pluggable.php) starts with:

```php
$pre_wp_mail = apply_filters( 'pre_wp_mail', null, $atts );
if ( null !== $pre_wp_mail ) {
    return $pre_wp_mail;
}
```

where `$atts = compact( 'to', 'subject', 'message', 'headers', 'attachments' )` after
`wp_mail` (WP ≥ 5.7). That short-circuit is the entire integration — no PHPMailer
reconfiguration, no `phpmailer_init`, no output rewriting. `Mailer::handle( $null, $atts )`:

1. **Disabled → `null`.** The master switch is off: return the incoming `null` untouched and
   WordPress's own mailer proceeds. Activating the plugin changes nothing until the switch is on —
   same promise as the Matomo plugin.
2. **Enabled → build and send.** Parse the `wp_mail` args into a `Message` (§5), force the From
   (§6), and hand it to the vendored `GraphMailer` wired with the WordPress adapters (§7).
3. **Success → fire `wp_mail_succeeded`** (the action core itself fires since 5.9; short-circuiting
   skips it, so we fire it with the same `$mail_data` shape) **and return `true`** — which becomes
   `wp_mail()`'s return value.
4. **Failure → never silent.** Catch `GraphException`, log the redacted message at error level via
   `error_log` (always — this is the brief's hard rule), and fire `wp_mail_failed` with a
   `WP_Error( 'wp_mail_failed', $message, $data )` whose `$data` carries the original mail atts
   plus the Graph HTTP status and redacted error body — the same shape core uses for a PHPMailer
   failure, with the Graph detail added.
   - Fallback **off** (the default): return `false` — `wp_mail()` reports failure.
   - Fallback **on**: log that the fallback is being taken, return `null` — core continues into
     its own PHPMailer path as if Missivus were not there.
5. **Enabled but misconfigured** is a failure (path 4), not a quiet pass-through: an operator who
   switched Missivus on is owed a loud error, not email that silently goes out some other way.
   `Settings::get_configuration_problem()` produces the human-readable reason, mirroring
   `Configuration\Settings::getConfigurationProblem()` upstream.

The test-email endpoint (§9) bypasses the fallback deliberately, calling the transport directly —
a test that quietly succeeded over PHPMailer would tell the operator nothing about their tenant.

## 5. Parsing the `wp_mail` arguments

`WpMailParser` owns everything between "what `wp_mail` was given" and "a `Message`". It mirrors
core's own parsing in `wp_mail()` so behaviour matches what site owners already observe:

- **Recipients (`to`)**: string (comma-separated) or array. Each entry may be a bare address or
  RFC-style `Name <address>`. Same for every address-bearing header.
- **Headers**: string (newline-separated, `\r\n` or `\n`) or array. Each line split on the first
  `:`. Recognised, case-insensitively: `From` (address + display name), `Reply-To`, `Cc`, `Bcc`,
  `Content-Type`. Multiple `Cc`/`Bcc`/`Reply-To` lines accumulate, and comma-separated lists
  within one line are split — both forms occur in the wild.
- **Content type**: `text/html` (any parameters ignored beyond it) selects the HTML body;
  anything else — or no header — is plaintext. The `wp_mail_content_type` filter is applied to
  the parsed value, as core would, so themes/plugins that force HTML that way keep working.
  Charset parameters are not converted: Graph takes UTF-8 JSON, and WordPress has defaulted to
  UTF-8 since 2010; the `wp_mail_charset` filter is intentionally not re-implemented (documented
  in the FAQ).
- **Other headers are dropped**, and each dropped one is noted at warning level in debug log —
  Graph's `sendMail` cannot carry arbitrary headers, only `x-`-prefixed
  `internetMessageHeaders`, and inventing partial support would be worse than a documented rule.
- **Attachments**: string (newline-separated) or array of file paths. A string array key is used
  as the attachment filename (`array( 'report.pdf' => '/tmp/x' )` — supported by core since 6.2),
  otherwise `basename()`. Bytes are read into memory (`Attachment` holds raw bytes, which is how
  the transport works); an unreadable path is a hard `GraphException` — PHPMailer likewise fails
  that send. MIME type from `wp_check_filetype()`, falling back to `application/octet-stream`.
  Size-based routing (inline vs upload session) is entirely the vendored `GraphMailer`'s decision
  (< 3 MB per file and < 3 MB total → inline; otherwise draft → chunked upload → send) — there is
  deliberately no setting that can route a large file down the inline path.

## 6. Forced From

Application-only Graph sends as `/users/{sender}` and Exchange rejects a mismatched From, so the
configured sender mailbox always wins — identical policy and near-identical code to
`GraphTransport::applyForcedFrom()` upstream:

- `Message::setFrom( sender, requested display name )` — the display name is kept even when the
  address is overridden.
- When the requested From address differs (case-insensitive compare), log a warning naming both
  addresses, and add the requested address as Reply-To **only if nothing else claimed Reply-To**.
- Docs tell the operator: set the WordPress admin email (or any `wp_mail_from` filter) to the
  shared mailbox, or accept the override. The default `wordpress@sitedomain` From that core
  fabricates will differ, so the warning also names the constant fix.

## 7. The WordPress adapters

Thin, boring, and the only code that knows WordPress HTTP/caching exists — mirrors of
`Adapter/Matomo*.php` upstream:

- **`Adapter\HttpClient`** implements `HttpClientInterface` over `wp_remote_request()` with
  `method => POST|PUT`, string body passed verbatim, headers as given, `timeout` honoured,
  `sslverify` left at WordPress's default (on). A `WP_Error` return becomes a
  `\RuntimeException` (the contract: transport failure throws, HTTP error status does not).
  Response → `Contract\HttpResponse( code, body, headers )`;
  `wp_remote_retrieve_headers()` is case-insensitive already, `HttpResponse` lowercases again —
  harmless. WordPress's HTTP proxy support (`WP_PROXY_*`) and CA bundle apply automatically,
  which is the point of wrapping rather than curl-ing.
- **`Adapter\TokenCache`** implements `TokenCacheInterface` over
  `get_transient` / `set_transient` / `delete_transient`. The key arrives as
  `missivus.token.<sha1>` (40 + 15 chars — under the 172-char transient ceiling); it is passed
  through unchanged. A non-string or empty cached value reads as a miss. TTL ≤ 0 is not stored
  (`set_transient` with 0 would mean "never expire", which must not happen to a bearer token).
  On multisite, transients are per-site, which matches per-site configuration.
- **`Adapter\Logger`** implements `LoggerInterface` over `error_log( '[missivus] <level>: …' )`.
  Messages arrive already redacted (the transport guarantees it; the plugin passes its own
  messages through `Redactor` too). Errors are logged unconditionally — not gated on `WP_DEBUG` —
  because "nothing fails silently" is the product promise; info stays quiet unless `WP_DEBUG` is on.

## 8. Settings model

One option, `missivus_settings`, a flat array stored via `register_setting` with a sanitising
callback. Keys and their wp-config constant overrides:

| Option key | Constant | Sanitisation | Notes |
| --- | --- | --- | --- |
| `enabled` | — | bool | Master switch, default **off** |
| `tenant_id` | `MISSIVUS_TENANT_ID` | GUID or DNS domain, else rejected with an error notice | |
| `client_id` | `MISSIVUS_CLIENT_ID` | GUID | |
| `auth_method` | `MISSIVUS_AUTH_METHOD` | `secret` \| `certificate` | UI default: secret |
| `client_secret` | `MISSIVUS_CLIENT_SECRET` | ≤ 1024 bytes, no whitespace/control chars | **Write-only**: never redisplayed; empty submit keeps the stored value |
| `certificate_path` | `MISSIVUS_CERTIFICATE_PATH` | absolute path, no NUL | Readability is checked at send/settings-display time, not stored |
| `certificate_passphrase` | `MISSIVUS_CERTIFICATE_PASSPHRASE` | as client_secret | Write-only |
| `sender_mailbox` | `MISSIVUS_SENDER` | `is_email()` | The brief names this constant `MISSIVUS_SENDER` |
| `save_to_sent` | — | bool | Default off, matching the transport default |
| `fallback_to_wp_mail` | — | bool | Default **off** — a visible failure beats silent divergence |

Rules, mirrored from the Matomo `Configuration\Settings` precedence design (constants play the
role config.ini.php + environment played there):

- **A defined constant wins over the option**, for every listed key. `Settings::get( key )` checks
  `defined( 'MISSIVUS_…' )` first.
- **The UI shows "Defined in wp-config.php"** for an overridden field: rendered disabled, value
  *not* echoed for secrets (identifiers may be shown — they are identifiers, not secrets).
- **A constant-defined secret is never written to the database**: the sanitise callback discards
  any posted value for a key whose constant is defined, keeping whatever the option already held.
- Validation is on *write* (option save) and on *read* nothing is trusted twice: values feed
  `Credentials`, whose `validate()` does the "what is missing" reporting, and `Endpoint::normalise`
  guards the two base URLs. Emptiness is never a save error — the master switch is off by default
  precisely so a half-filled page is a legitimate state.
- Base URLs (`MISSIVUS_GRAPH_BASE_URL`, `MISSIVUS_LOGIN_BASE_URL`) are constant-only overrides for
  sovereign clouds and the test suite, defaulting to the public endpoints — the same
  "config-only, no UI" posture as upstream.
- Secrets stored via the UI live in `wp_options` in clear, exactly as every SMTP plugin's password
  does; the constants tier exists so they need not, and `docs/SECURITY.md` §WordPress carries the
  same "mitigated, not eliminated" honesty as the Matomo review's finding 11.

Multisite: `get_option` is per-site, so each site configures (or constant-overrides) its own
sender. Network activation just activates per site; no network-admin UI in v1 (brief decision).

## 9. Settings page and test email

**Settings → Missivus** (`add_options_page`, capability `manage_options`). Sections: the master
switch; Microsoft identifiers (tenant, client); authentication (method select, secret,
certificate path + passphrase); sender mailbox; behaviour (save to Sent, fallback); and the
external-services disclosure stating plainly that the plugin talks to
`login.microsoftonline.com` and `graph.microsoft.com` and what is sent to each. One tasteful line
points to Solvetus for paid installation and support.

**Send test email**: its own form on the same page posting to `admin-post.php` with action
`missivus_send_test` — not part of the option save. Handler order: `current_user_can(
'manage_options' )`, `check_admin_referer( nonce )`, `is_email( recipient )`, then send **without
fallback**. The result — success, or the redacted Microsoft error body — is stashed in a
60-second per-user transient and rendered as an inline notice after the redirect back. The button
is disabled (with the reason named) until the **saved** configuration is complete and the switch
is on — the same "the test sends with what is stored, not what is on screen" rule the Matomo
plugin learned in v0.1.1. Nothing about the endpoint is reachable without the capability and the
nonce; the recipient travels in the POST body.

## 10. Internationalisation

Text domain `missivus`, every user-facing string through `__()`/`esc_html__()` and friends.
`languages/missivus.pot` generated with `wp i18n make-pot`; PT-PT, FR, ES and IT `.po` files
authored in-repo (AI-produced, human-reviewed — stated in readme.txt), compiled to `.mo` with
`wp i18n make-mo`. `load_plugin_textdomain` on `init` so the shipped `.mo` files work on
installs that never touch translate.wordpress.org.

## 11. Security posture

Everything the Matomo review (docs/SECURITY.md upstream) established, inherited by vendoring, plus
the WordPress-specific surface:

- Capability check + nonce on every admin action; sanitise on input, escape on output, everywhere.
- Secrets: never echoed into HTML (write-only password fields), never in JS, never in REST (there
  is no REST surface in v1), never logged (Redactor wraps every string that leaves the plugin),
  never committed. `Credentials` neuters dumps. A constant-defined secret never touches the DB.
- The token transient holds a live bearer token: same "accepted, documented" posture as upstream
  finding 9 — it lives ≤ 55 minutes and is scoped by the Exchange access policy to one mailbox;
  anyone who can read the options table can already read `wp-config.php`.
- Endpoint normalisation makes credential exfiltration via a base-URL override impossible
  (upstream finding 1); the upload URL is redacted as a credential (finding 2).
- `docs/SECURITY.md` here mirrors the Matomo document and adds the WordPress section.

## 12. Test strategy

Three gates, all green before release:

1. **PHPUnit** (phar, dev-only) with hand-rolled stubs: `tests/bootstrap.php` defines the small
   set of WordPress functions the plugin touches (`apply_filters`, `do_action`, `get_option`,
   transients, `wp_remote_request`, `is_email`, `esc_*`, …) as recording fakes — the same
   pattern as `tests/Framework/PiwikStubs.php` upstream, and the same `FakeHttpClient` /
   `FakeCache` / `RecordingLogger` doubles. A mocked Graph endpoint (scripted `HttpResponse`s)
   covers, per the brief: send happy path (202); header parsing — from/reply-to/cc/bcc/
   content-type in string and array forms; HTML vs plain; attachments inline and large (upload
   session chunks asserted); forced-From warning + Reply-To preservation; fallback OFF (returns
   false, `wp_mail_failed` fired, error logged) and ON (returns null after logging); constant
   overrides (constant wins, UI-value discarded, secret not written); secret redaction in logs
   and error output; capability and nonce rejection on the test endpoint.
2. **PHPCS** with WordPress-Coding-Standards (dev-only Composer), `src/` + root PHP files;
   the vendored tree is excluded from WPCS style rules (it is upstream's code style, changing it
   would violate the boundary) but included in `php -l` and PHPCompatibility.
3. **Plugin Check** (the official `plugin-check` plugin) run via WP-CLI inside the local smoke
   install; plus `php -l` on every file under a PHP 7.2 container (WordPress's floor).

**Smoke run** (also the goal's gate 5): latest WordPress installed locally via wp-cli with the
SQLite drop-in, plugin symlinked and activated, settings page fetched authenticated, test-email
posted with placeholder config — asserting the failure is loud (the Microsoft/config error is
shown) and never silent. No live site is touched.

## 13. WordPress internals depended on

The exact list to re-verify after a WordPress upgrade, mirroring PLAN.md §10 upstream:

| Internal | Where | Since | What we assume |
| --- | --- | --- | --- |
| `pre_wp_mail` filter | wp-includes/pluggable.php `wp_mail()` | 5.7 | Non-null return short-circuits and becomes the return value; `$atts` keys `to, subject, message, headers, attachments` |
| `wp_mail_failed` action | same, catch block | 4.4 | Takes a `WP_Error`; data carries the mail atts |
| `wp_mail_succeeded` action | same, after send | 5.9 | Takes the `$mail_data` array |
| `wp_mail_content_type` filter | same | 2.3 | String content type |
| HTTP API | `wp_remote_request()` | 2.7 | `method`, `body` (string passed verbatim), `headers`, `timeout` args; `WP_Error` on transport failure |
| Transients | `get/set/delete_transient` | 2.8 | TTL respected; per-site on multisite |
| Settings API | `register_setting` + sections/fields | 2.7 | Sanitise callback runs on save |
| `admin_post_{action}` | wp-admin/admin-post.php | 2.6 | Fires for logged-in POSTs |
| `add_options_page` | wp-admin | 1.5 | `manage_options` gate |
| `is_email`, `wp_check_filetype`, `sanitize_text_field`, `wp_unslash`, `esc_*` | various | — | Standard behaviour |
| `load_plugin_textdomain` | l10n | 1.5 | Loads `languages/*.mo` |

## 14. Build and release

`tools/build-zip.sh`: allowlist copy (`missivus.php`, `uninstall.php`, `readme.txt`, `LICENSE`,
`src/`, `languages/`) into a staging `missivus/` folder, zip to `dist/missivus-<version>.zip`.
No tests, no tools, no docs-internal, no composer files, no `.github`. Version read from the
plugin header so the script cannot disagree with the code.

Release: small logical commits on `main`, push, tag `v0.1.0`, GitHub Release carrying the zip,
repo description/topics/homepage (missivus.com) set via `gh`. wordpress.org submission is the
owner's, not the build's.

## 15. Self-review against the brief

Every brief line, and where it is satisfied:

- Same transport vendored unchanged, same decisions, same voice → §2, diff shown at release; docs
  adapted from upstream with identical PowerShell blocks.
- Repo/slug/file/text-domain/namespace/remote/branch/identity → §1, §3; git initialised with the
  includeIf identity (verified: `git config user.email` before first commit).
- `pre_wp_mail` as primary seam; `wp_mail_failed`; `phpmailer_init` only if needed (it was not
  needed — the seam covers everything; noted here as the decision) → §4.
- Header parsing for From name, Reply-To, Cc, Bcc, Content-Type; HTML and plaintext → §5.
- Graph errors surfaced in `WP_Error`, `error_log` never swallowed, fallback default OFF via
  returning `null` → §4 steps 4–5, §8 table.
- Client secret AND certificate; UI default secret; token in a transient with margin → §2
  (TokenProvider), §7, §8.
- Forced From + warning + docs guidance → §6.
- Attachments inline to 3 MB (verified against the transport constants: 3 145 728 bytes per file
  and total, 4 MB request bound), upload session above, both tested; Mail.ReadWrite documented →
  §5, §12, docs.
- Settings page fields, capability, write-only secret, stored in `wp_options` → §8, §9.
- The seven wp-config constants, constant wins, "set in wp-config.php" shown, constant secret
  never written → §8.
- Test-email gate on saved+enabled (the Matomo v0.1.1 UX rule) → §9.
- Zero runtime deps, own PSR-4 loader, PHP floor = WordPress's minimum, no newer syntax in the
  vendored class → §3, §2.
- Multisite per-site, network activation without special UI → §8.
- Security: capabilities, nonces, sanitise/escape, secret hygiene, SECURITY.md with WP section →
  §11.
- External-services disclosure in readme.txt and the settings page → §9, readme.
- i18n: domain, .pot, four translations with the AI-produced/human-reviewed line → §10.
- Tests: the enumerated PHPUnit cases, PHPCS + WPCS, Plugin Check → §12.
- Docs set: readme.txt (≤150-char short description, Description, Installation, FAQ, Screenshots
  listing owner-supplied files, Changelog), README.md with the mark, docs/INSTALL.md from
  upstream `docs/index.md` with identical PowerShell blocks, faq.md, SECURITY.md,
  CONTRIBUTING.md, LICENSE, CHANGELOG.md, social-preview.png "for WordPress" → §14, docs.
- Build zip allowlist with `missivus/` top folder → §14.
- No wordpress.org submission, no live site, version 0.1.0, no secrets ever → throughout.
