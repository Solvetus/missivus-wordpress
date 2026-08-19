=== Missivus ===
Contributors: solvetus
Tags: email, microsoft 365, graph api, smtp, wp mail
Requires at least: 5.7
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 0.1.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Missivus for WordPress — send WordPress email through Microsoft Graph with application permissions and a shared mailbox. No SMTP, no user login.

== Description ==

WordPress has no email API of its own. `wp_mail()` sends only through PHPMailer, over SMTP or PHP's `mail()` — and `mail()` from a web server usually lands in spam regardless. For Microsoft 365 that means SMTP AUTH with basic authentication — a licensed user's password stored on your server as a shared credential, on a path Microsoft is retiring: disabled by default for existing tenants from the end of December 2026, unavailable to new tenants from 2027, with final removal to follow in the second half of 2027. WordPress's PHPMailer path cannot do the OAuth2 SMTP that replaces it. Sooner or later the password resets, order confirmations and form notifications stop.

Missivus routes every `wp_mail()` call through the Microsoft Graph API using **OAuth2 client credentials** and the **Mail.Send application permission**, sending as one shared mailbox you nominate. Every email WordPress produces goes out that way — there is nothing to switch over form by form.

= Application permissions, not a delegated login =

The usual Microsoft 365 mailers use *delegated* authentication: a multi-tenant app, a redirect URI, and a human who clicks "Connect" so that mail goes out as their personal account. That is the wrong shape for a server. It breaks when that person leaves, when their password changes, and when MFA policy tightens — and it is usually the paid tier.

Missivus uses an app registration of your own with application permissions:

* **Who sends:** a shared mailbox, not a named person's account.
* **Setup:** nothing to click, nobody signs in, ever.
* **Survives an employee leaving:** yes.
* **Mailbox licence needed:** no — a shared mailbox is free.
* **Scope:** one mailbox, enforced by an Exchange application access policy.
* **Cost:** free, GPLv3.

The scoping is what makes this safe. An **Exchange application access policy** restricts the app registration to the single shared mailbox, so the Mail.Send permission cannot touch any other mailbox in your tenant — and the install guide treats that step as first-class, with a command to verify it actually took effect.

= What it does =

* **Everything `wp_mail()` supports:** HTML and plaintext, multiple recipients, Cc, Bcc, Reply-To, and attachments.
* **Large attachments never fail on size.** Files under 3 MB go inline; anything larger is uploaded through a Graph upload session automatically. There is no setting that can get this wrong.
* **Client secret or certificate.** A client secret is the quickest way in and is the default; a certificate is supported as optional hardening.
* **Secrets can stay out of the database.** Every value can come from a constant in `wp-config.php` (`MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_ID`, `MISSIVUS_CLIENT_SECRET`, `MISSIVUS_SENDER`, and friends), which then wins over the settings UI and is never written to the options table.
* **Nothing fails silently.** A Graph failure is logged at error level, announced on the `wp_mail_failed` action with the exact Microsoft error attached, and — unless you explicitly turn on the fallback — reported as a failed send. Nothing is swallowed.
* **A test-email button** that shows you the exact error Microsoft returned.
* **No third-party runtime dependencies.** No Composer, no SDK, nothing beyond what WordPress already ships.

= External services =

This plugin talks to two Microsoft endpoints, and only when it sends email:

* **login.microsoftonline.com** — to obtain an access token. Your Directory (tenant) ID, Application (client) ID and the app credential are sent there.
* **graph.microsoft.com** — to send the message. The email's content, recipients and attachments are sent there.

Nothing else leaves your site, nothing is sent anywhere while the plugin is switched off, and no data is shared with Solvetus or any other party. Both endpoints are operated by Microsoft; see [Microsoft's privacy statement](https://privacy.microsoft.com/privacystatement) and [product terms](https://www.microsoft.com/licensing/terms/product/PrivacyandSecurityTerms/all).

= What you need =

* WordPress 5.7 or later, PHP 7.2 or later with the `openssl` and `json` extensions.
* A Microsoft 365 tenant, and an administrator who can create an app registration, grant admin consent, and run one Exchange Online PowerShell command.
* A shared mailbox to send from. It needs **no licence**.

Missivus is free and open source (GPLv3), from [Solvetus](https://solvetus.com). If you would rather not do the Entra and Exchange setup yourself, Solvetus offers paid installation and support. Translations (PT-PT, FR, ES, IT) are AI-produced and human-reviewed — corrections welcome.

== Installation ==

1. Install and activate the plugin. Activating changes nothing on its own — Missivus starts switched off and WordPress keeps sending mail exactly as before.
2. Create an app registration in Microsoft Entra, grant it the **Mail.Send** application permission (plus **Mail.ReadWrite** if you send attachments over 3 MB), grant admin consent, and give it a client secret. The [step-by-step guide](https://github.com/Solvetus/missivus-wordpress/blob/main/docs/INSTALL.md) spells out every click — it assumes you have never opened Microsoft Entra.
3. Create a **shared mailbox** to send from, and scope the app to it with an **Exchange application access policy** — the guide's Part 5, with a command that proves it took effect.
4. In WordPress, go to **Settings → Missivus**, fill in the Directory (tenant) ID, Application (client) ID, client secret and sender mailbox, tick **Send email through Microsoft Graph**, and click **Save Changes**.
5. Press **Send test email**. The button stays disabled until the saved settings are complete, and failures show the exact error Microsoft returned.

Prefer credentials in code rather than the database? Define any of `MISSIVUS_TENANT_ID`, `MISSIVUS_CLIENT_ID`, `MISSIVUS_AUTH_METHOD`, `MISSIVUS_CLIENT_SECRET`, `MISSIVUS_CERTIFICATE_PATH`, `MISSIVUS_CERTIFICATE_PASSPHRASE`, `MISSIVUS_SENDER` in `wp-config.php`. A constant wins over the settings page, the field shows "Defined in wp-config.php" instead of its value, and a constant-defined secret is never written to the database.

== Frequently Asked Questions ==

= Does the shared mailbox need a Microsoft 365 licence? =

No. An Exchange Online shared mailbox is free, up to 50 GB, and needs no licence assigned to it — which is the point. Missivus authenticates as an application, not as the mailbox, so nobody has to sign in as it and nothing has to be paid for it.

= Why not just use an SMTP plugin? =

Because it is on borrowed time, and because of what it costs you today. Microsoft is retiring basic-authentication SMTP AUTH for Microsoft 365: it is disabled by default for existing tenants from the end of December 2026, unavailable to new tenants from 2027, and the final removal date will be announced in the second half of 2027. What remains after that is SMTP AUTH with OAuth2, which WordPress's PHPMailer path does not speak. And even while it still works, SMTP means a licensed user account whose password sits on your server as a shared credential, with no way to scope what it can do. Graph with application permissions has neither problem: no password, no user, no licence, and the credential is scoped by Exchange to one mailbox.

= Will installing Missivus break my email if I do nothing? =

No. It ships switched off. Activating the plugin changes nothing at all: `wp_mail()` keeps using whatever WordPress was already using until you tick "Send email through Microsoft Graph". Deactivating restores stock behaviour with no cleanup.

= What does the From address become? =

The shared mailbox, always. Application-only Graph can only send as the nominated mailbox, and Exchange rejects anything else, so Missivus forces it. When something asked for a different From, the requested address is kept as Reply-To and a warning naming both addresses is written to the log. Set your site's From address to the shared mailbox to silence it.

= What happens with attachments over 3 MB? =

They still send. Graph's inline limit is 3 MB, so above it Missivus automatically switches to Graph's large-file path: create a draft, upload in chunks, send. The decision is per message and total-aware; there is no setting for it. This path needs the Mail.ReadWrite application permission — if it is missing, the failure is loud and names the permission.

= What happens when Microsoft is down or the secret expired? =

The send fails loudly: `wp_mail()` returns false, the `wp_mail_failed` action fires with the exact Microsoft error attached, and the same error is written to the PHP error log. If you would rather WordPress fell back to its own mailer, there is a switch for that — off by default, deliberately: a failure you can see beats an email that quietly goes out misconfigured.

= Does it work on multisite? =

Yes, per site: each site has its own settings (or its own constants) and sends as its own configured mailbox. Network activation works; there is no network-wide settings screen in this version.

= Where do I report a bug, or a security problem? =

Bugs and feature requests: [the issue tracker](https://github.com/Solvetus/missivus-wordpress/issues). Security problems: email security@missivus.com instead, and please give us a chance to fix it before it becomes public.

== Screenshots ==

1. The settings page: identifiers, authentication, sender mailbox, and the behaviour switches.
2. The test-email button, with the exact Microsoft error shown on failure.

== Changelog ==

= 0.1.2 =
* Docs: corrected the SMTP AUTH retirement timeline to match Microsoft's 2026-01-27 schedule update — the previous wording read as though basic-auth SMTP was already dead rather than being phased out on a schedule. No code changes.

= 0.1.1 =
* Security: endpoint override URLs are no longer repeated back into errors, the log, or the admin notice. An unsafe MISSIVUS_GRAPH_BASE_URL / MISSIVUS_LOGIN_BASE_URL was refused correctly, but the refusal echoed the rejected value verbatim, so a base URL carrying credentials or a token could reach the PHP error log and the WordPress admin. Endpoint now builds messages from scheme, host, port and path only; Redactor blanks credentials, secret-looking parameters and fragments in a URL; and Mailer::redact() is the single final pass over everything the mailer logs, hands to wp_mail_failed, or throws. Reported by @textagroup (Kirk Mayo). No configuration change is needed.
* The vendored Graph transport is resynced from missivus-matomo v0.1.4, and remains byte-for-byte identical to it.

= 0.1.0 =
* Initial release. Routes every wp_mail() through Microsoft Graph sendMail with OAuth2 client credentials (secret or certificate, PS256 with an RS256 escape hatch), token caching in a transient with a five-minute refresh margin, forced-From with a Reply-To keep, inline and upload-session attachments, wp-config.php constant overrides, a test-email button, and an off-by-default fallback to the stock mailer. Nothing fails silently.

== Upgrade Notice ==

= 0.1.2 =
Docs-only correction to the SMTP retirement timeline and a few stale references. No functional change; upgrading is optional.

= 0.1.1 =
Security fix: a misconfigured Graph or login base URL could previously have its credentials written into the error log and the admin notice. Upgrading is recommended; nothing to reconfigure.

= 0.1.0 =
Initial release.
