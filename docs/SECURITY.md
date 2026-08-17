# Missivus — security review

The standing security document for Missivus for WordPress v0.1.0.

Two halves. The first is inherited: the Microsoft Graph transport under
`src/Vendor/Solvetus/Missivus/` is vendored **byte-for-byte unchanged** from
[missivus-matomo](https://github.com/Solvetus/missivus-matomo), whose
[security review](https://github.com/Solvetus/missivus-matomo/blob/main/docs/SECURITY.md)
(performed 2026-08-17 against its v0.1.1 tree) covers it in full — every finding below marked
*(inherited)* was fixed or verified there and arrives here by vendoring. The second half is the
WordPress-specific surface: the settings model, the admin page, the test-email endpoint and the
adapters, written against this tree.

Nothing in this document contains a credential.

---

## Summary

| # | Property | Status |
| --- | --- | --- |
| 1 | A base-URL override cannot aim credentials at another host | **Enforced** *(inherited)* |
| 2 | A mid-upload failure cannot leak the pre-authenticated upload URL | **Enforced** *(inherited)* |
| 3 | Secret redaction in logs and exceptions | **Enforced** *(inherited)*, tested here too |
| 4 | Secrets in HTML source, page output, or the browser | **Verified clean** |
| 5 | Capability and nonce on the test-email endpoint | **Verified** |
| 6 | Input validation on every setting | **Enforced** |
| 7 | A constant-defined secret never touches the database | **Enforced** |
| 8 | The Graph access token is cached in a transient | **Accepted, documented** |
| 9 | A superuser can send test mail to any address, unthrottled | **Accepted, documented** |
| 10 | A DB-stored client secret is stored in plaintext in wp_options | **Mitigated, not eliminated** |

---

## Inherited from the transport (and why it matters here)

### 1. Base URLs are refused unless they are bare https origins

`MISSIVUS_GRAPH_BASE_URL` and `MISSIVUS_LOGIN_BASE_URL` exist for sovereign clouds and the test
suite. Either one is a knob that could aim a client secret or a live bearer token at a host we
never meant to talk to — so `Endpoint::normalise()` refuses anything that is not a bare `https`
origin (no `http`, no embedded credentials, no query string, no malformed host) *before any request
is built*. The refusal follows the normal failure policy: logged, announced on `wp_mail_failed`,
never silent.

### 2. The upload URL is treated as a credential

Graph's large-attachment upload URL is pre-authenticated: anyone holding it can write to that
draft. A transport failure mid-upload is converted into a redacted `GraphException` with the URL
masked out, and `Redactor` blanks any `uploadUrl` field in an echoed response body.

### 3. Redaction on the way to a log or an error

Every string Missivus logs or shows passes through `Redactor` first: the literal secrets we hold
are blanked wherever they appear (which catches Entra echoing a submitted value back), and shape
matching blanks `access_token` / `client_secret` / `client_assertion` / `uploadUrl` JSON fields,
form-encoded credentials, `Bearer` headers, and bare JWTs. A `preg_replace` failure returns the
mask, not the input: it fails closed. `Auth\Credentials` additionally neuters `__toString()` and
`__debugInfo()`, so a `var_dump` or a stack trace renders `credential=redacted`.

The suite here re-tests this through the WordPress path: an Entra error body deliberately echoing
the configured secret reaches the log and the settings page with the secret masked
(`MailerTest::testAnEntraErrorEchoingTheSecretIsRedactedEverywhere`,
`TestEmailEndpointTest::testAFailedTestShowsTheMicrosoftErrorBodyRedacted`).

---

## The WordPress surface

### 4. Secrets never reach the page

The client secret and certificate passphrase are **write-only**: the settings page renders their
`<input type="password">` with an empty `value` always — a stored secret shows only the placeholder
*"Saved — enter a new value to replace it"*, and a constant-supplied one shows *"Defined in
wp-config.php"*. The smoke run greps the rendered settings HTML for the configured secret and finds
zero occurrences. There is no JavaScript in the plugin at all, and no REST surface.

An empty submission keeps the stored value, so saving the page cannot blank a secret — and cannot
echo one either.

### 5. The test-email endpoint

`admin_post_missivus_send_test`, in order: `current_user_can( 'manage_options' )` (wp_die 403
otherwise), `check_admin_referer()` (nonce), `is_email()` on the recipient. The recipient travels
in the POST body; the result is stashed in a 60-second per-user transient and rendered — escaped —
after a redirect, so the error body never rides in a URL. The settings page itself is registered
with the `manage_options` capability, and WordPress's own admin-post plumbing means an
unauthenticated request never reaches the handler (`admin_post_nopriv_…` is deliberately not
registered).

The Graph error body is deliberately shown to the operator — it is the single most useful thing for
diagnosing a broken app registration — and only from behind that capability check, redacted first.

### 6. Input validation

Applied in the option's sanitise callback, so nothing invalid is stored: tenant ID must be a GUID
or a DNS domain; client ID a GUID; sender mailbox an email address; certificate path absolute with
no NUL byte; a secret longer than 1024 bytes or carrying whitespace/control characters — always a
copy-paste accident, and miserable to diagnose when nothing may print the value back — is rejected
on entry. Emptiness is never an error: the master switch is off by default precisely so a
half-filled settings page is a legitimate state. At the point of use, the tenant ID and sender
mailbox are additionally `rawurlencode()`d into URL paths by the transport.

### 7. The constants tier keeps secrets out of the database

Any `MISSIVUS_*` constant wins over the stored option, the UI renders the field disabled without
its value, and the sanitise callback **discards** a posted value for any constant-backed key — a UI
submission cannot copy a wp-config-managed secret into `wp_options`
(`ConstantOverridesTest::testAConstantDefinedSecretIsNeverWrittenToTheDatabase`).

---

## Accepted, and not fully fixed

### 8. The access token is cached in a transient

The bearer token lives in a WordPress transient — the options table, or the object cache if the
site has one. Anyone who can read that store can send as the shared mailbox until the token
expires. Not fixed, deliberately: the alternatives are no cache at all (a token round-trip to
Microsoft on every single email) or inventing at-rest encryption whose key would live next to the
data. The mitigations are real: the token lives at most 55 minutes, it is scoped by the Exchange
application access policy to one mailbox, and **anyone who can read wp_options can already read
`wp-config.php`**, which may hold the client secret itself — the token is not the weakest link in
that scenario.

### 9. Test emails are unthrottled

An administrator can click **Send test email** repeatedly, to any address. The subject and body are
fixed, the recipient is the only chosen part, and the actor must already hold `manage_options` —
who could in any case install any plugin or reconfigure mail outright. A nuisance vector, not a
privilege one; Exchange Online's outbound throttling is the backstop. Worth revisiting if the
plugin ever gains a non-admin path to sending.

### 10. A client secret stored in the database is stored in clear

If the operator enters the client secret in the settings page, it is stored in `wp_options` as
plain text — exactly as every SMTP plugin's password is. WordPress offers no at-rest secret
encryption, and inventing one here would be false comfort: the key would have to live next to the
data. Mitigated rather than eliminated: the `wp-config.php` constants tier exists precisely so the
secret need never touch the database, it takes precedence, and when present the UI refuses to
write a secret at all. Certificate authentication — where WordPress stores only a *path* — remains
the strongest option.

---

## Not in scope, worth stating

- **The Exchange application access policy is what bounds the blast radius.** Every risk above is
  contained by it: even full compromise of the credential only permits sending as one shared
  mailbox. [docs/INSTALL.md](INSTALL.md) Part 5 treats it as a required step, with a verification
  command, for that reason.
- **Transport security to Microsoft.** All requests go through the WordPress HTTP API, so the
  site's CA bundle and proxy configuration apply, TLS verification stays at WordPress's default
  (on), and after inherited finding 1 the scheme can never be anything but `https`.
- **Multisite.** Options and transients are per-site, so one site's credentials are as reachable to
  a super admin as everything else on a network is — which is the multisite trust model, not a
  property this plugin can change.

## Reporting a vulnerability

Email <security@missivus.com> with the details. Please do not open a public issue for a security
problem until it has been fixed.
