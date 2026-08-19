<p align="center">
  <a href="https://missivus.com"><img src=".github/mark.svg" alt="Missivus" width="40" height="41"></a>
</p>

# Missivus for WordPress

**Missivus for WordPress — send WordPress email through Microsoft Graph with application permissions
and a shared mailbox. No SMTP, no user login. Free, GPLv3.**

WordPress has no email API of its own. `wp_mail()` sends only through PHPMailer, over SMTP or
PHP's `mail()` — and `mail()` from a web server usually lands in spam regardless. For Microsoft
365 that means SMTP AUTH with basic authentication — a licensed user's password stored on your
server as a shared credential, on a path Microsoft is retiring: disabled by default for existing
tenants from the end of December 2026, unavailable to new tenants from 2027, with final removal to
follow in the second half of 2027. WordPress's PHPMailer path cannot do the OAuth2 SMTP that
replaces it. Sooner or later the password resets, order confirmations and form notifications stop.

Missivus short-circuits `wp_mail()` — through WordPress's own `pre_wp_mail` seam — into the
Microsoft Graph API, using **OAuth2 client credentials** and the **Mail.Send application
permission**, sending as one shared mailbox you nominate. Every email WordPress produces goes out
that way — there is nothing to switch over form by form.

### Application permissions, not a delegated login

The usual Microsoft 365 mailers use *delegated* authentication: a multi-tenant app, a redirect URI,
and a human who clicks "Connect" so that mail goes out as their account. That is the wrong shape for
a server. It breaks when that person leaves, when their password changes, and when MFA policy
tightens.

| | Delegated mailers | Missivus |
| --- | --- | --- |
| Who sends | A named person's account | A shared mailbox |
| Setup | A human clicks "Connect" | Nothing to click |
| Survives an employee leaving | No | Yes |
| Mailbox licence needed | Yes | No |
| Scope | Whatever that person can reach | One mailbox, enforced by Exchange |
| Cost | Often a paid extension | Free, GPLv3 |

The scoping is what makes this safe. An **Exchange application access policy** restricts the app
registration to the single shared mailbox, so the `Mail.Send` permission cannot touch any other
mailbox in your tenant — and the install guide treats that step as first-class, with a command to
verify it actually took effect.

### What it does

- **Everything `wp_mail()` supports:** HTML and plaintext, multiple recipients, Cc, Bcc, Reply-To,
  and attachments.
- **Large attachments never fail on size.** Files under 3 MB go inline; anything larger is uploaded
  through a Graph upload session automatically. There is no setting that can get this wrong.
- **Client secret or certificate.** A client secret is the quickest way in and is the default; a
  certificate is supported as optional hardening.
- **Secrets can stay out of the database.** Every value can come from a `MISSIVUS_*` constant in
  `wp-config.php`, which then wins over the settings UI and is never written to the options table.
- **Nothing fails silently.** A Graph failure is logged at error level, announced on
  `wp_mail_failed` with the exact Microsoft error attached, and — unless you explicitly turn on the
  fallback — reported as a failed send. Nothing is swallowed.
- **A test-email button** that shows you the exact error Microsoft returned.
- **No third-party runtime dependencies.** No Composer, no SDK, nothing beyond what WordPress
  already ships.

### What you need

- WordPress 5.7 or later, PHP 7.2 or later with the `openssl` and `json` extensions
- A Microsoft 365 tenant, and an administrator who can create an app registration, grant admin
  consent, and run one Exchange Online PowerShell command
- A shared mailbox to send from. It needs **no licence**

### Getting started

Install the plugin, then follow **[the installation guide](docs/INSTALL.md)** — it is written for
someone who has never opened Microsoft Entra, and every click is spelled out. Budget about an hour
for the Microsoft side. The [FAQ](docs/faq.md) answers the questions that come up most often, and
[docs/SECURITY.md](docs/SECURITY.md) is the standing security review.

Missivus is free and open source (GPLv3). If you would rather not do the Entra and Exchange setup
yourself, [Solvetus](https://solvetus.com) offers paid installation and support.

## Install

1. **Upload the plugin.** Either upload `missivus-<version>.zip` through
   **Plugins → Add New Plugin → Upload Plugin**, or unzip it into `wp-content/plugins/` so the
   plugin ends up at `wp-content/plugins/missivus/` with `missivus.php` directly inside it.

2. **Activate it.** Activating changes nothing on its own — Missivus starts switched off and
   WordPress keeps sending mail exactly as before.

3. **Configure it.** Go to **Settings → Missivus** and fill in:

   - **Directory (tenant) ID** and **Application (client) ID** — both from your app registration's
     Overview page in Microsoft Entra
   - **Authentication method** — leave it on **Client secret**
   - **Client secret** — the secret **Value** from Certificates & secrets
   - **Sender mailbox** — the shared mailbox WordPress should send from
   - Tick **Send email through Microsoft Graph**

   Click **Save Changes**, then press **Send test email**. The button stays disabled until the
   saved settings are complete, and tells you what is missing. If the send fails, the exact error
   Microsoft returned is shown on the page.

### Getting the Microsoft side ready

The plugin needs an app registration before any of the above will work. Full step-by-step
instructions are in **[docs/INSTALL.md](docs/INSTALL.md)**. In outline:

1. Create an app registration in Microsoft Entra.
2. Grant it the **Mail.Send** application permission — plus **Mail.ReadWrite** if you send
   attachments over 3 MB — and grant admin consent.
3. Add a **client secret** (or a certificate, if you would rather — see the guide). Name it
   `missivus-wordpress-<your site hostname>`, so it stays identifiable among the other app
   registrations in your tenant.
4. Create the shared mailbox you want WordPress to send from. Make it a company-wide no-reply
   address — `noreply@yourcompany.com`, display name your company name — rather than a
   WordPress-specific one. Other tools can send from the same mailbox later: each gets its own app
   registration scoped by the same kind of policy, so credentials stay separate while the sender
   address stays consistent.
5. Create an **application access policy** in Exchange Online scoping the app to that one mailbox.

## Configuration

Everything is set in **Settings → Missivus**. Nothing below is required.

**Optionally**, any value can live in `wp-config.php` instead. This suits a site you deploy by
pulling code, where you would rather configuration travelled with your files than sat in the
database:

```php
define( 'MISSIVUS_TENANT_ID', '00000000-0000-0000-0000-000000000000' );
define( 'MISSIVUS_CLIENT_ID', '00000000-0000-0000-0000-000000000000' );
define( 'MISSIVUS_AUTH_METHOD', 'secret' );
define( 'MISSIVUS_CLIENT_SECRET', 'the Value from Certificates & secrets' );
define( 'MISSIVUS_SENDER', 'noreply@example.com' );
```

A constant wins over the settings page, the field shows *Defined in wp-config.php* instead of its
value, and a constant-defined secret is never written to the database. For a certificate instead,
swap the last two lines for `MISSIVUS_AUTH_METHOD` `'certificate'`, `MISSIVUS_CERTIFICATE_PATH`
and — if the key is encrypted — `MISSIVUS_CERTIFICATE_PASSPHRASE`.

Set your site's From address to the same shared mailbox. Application-only sending cannot use any
other From, so Missivus forces it either way — matching them just keeps a warning out of your log
(the requested address is preserved as Reply-To when they differ).

## Security

[docs/SECURITY.md](docs/SECURITY.md) is the standing security review: how secrets are kept out of
logs, page source and the database, how the test-email endpoint is authenticated, and the risks
that were accepted rather than eliminated, with the reasoning for each.

Found a vulnerability? Email <security@missivus.com> rather than opening a public issue.

## Development

```
php phpunit.phar -c phpunit.xml.dist    # the unit suite — mocked Graph, no WordPress loaded
vendor/bin/phpcs                        # WordPress-Coding-Standards (composer install first)
./tools/build-zip.sh                    # builds dist/missivus-<version>.zip
```

[PLAN.md](PLAN.md) documents the architecture, the `pre_wp_mail` seam, and — usefully before a
WordPress upgrade — the exact list of WordPress internals this plugin depends on.

The Microsoft Graph transport in [`src/Vendor/Solvetus/Missivus/`](src/Vendor/Solvetus/Missivus/)
is vendored **unchanged** from [missivus-matomo](https://github.com/Solvetus/missivus-matomo): it
depends only on `openssl`, `json`, a two-method HTTP interface and a three-method cache interface.
Fixes to it go upstream first, never here.

Release history is in [CHANGELOG.md](CHANGELOG.md).

## Licence

GPLv3 or later. See [LICENSE](LICENSE).

## Support

Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself,
[Solvetus](https://solvetus.com) offers paid installation and support.
