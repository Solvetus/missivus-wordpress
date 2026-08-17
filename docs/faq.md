# Frequently asked questions

## Does the shared mailbox need a Microsoft 365 licence?

No. An Exchange Online **shared mailbox** is free, up to 50 GB, and needs no licence assigned to it —
which is the point. Missivus authenticates as an *application*, not as the mailbox, so nobody has to
sign in as it and nothing has to be paid for it. (A licence is only required if you want to convert
it to a user mailbox, put it under litigation hold, or give it an archive.)

## Why not just use an SMTP plugin? There are dozens.

Because Microsoft has retired basic-authentication SMTP for Microsoft 365. What remains is SMTP AUTH
with OAuth2 — which is exactly the delegated-login dance the paid Microsoft 365 mailers do — or the
legacy username-and-password flow, which Microsoft disables tenant-wide and which requires a
licensed user account whose password then becomes a shared server credential.

Graph with application permissions has neither problem: there is no password, no user, no licence,
and the credential is scoped by Exchange to one mailbox.

## Client secret or certificate — which should I use?

Start with a **client secret**. It is two clicks in Entra, there is nothing to manage on the
filesystem, and it is the documented route throughout the guide.

A **certificate** is stronger, because the secret never travels in a request body: Missivus signs a
short-lived assertion with the private key instead. Use it if your security policy asks for it, or if
you would rather rotate a file on disk than a value in a database. The trade is that you now have a
PEM file to keep readable by the web server and nobody else, and to replace before it expires.

Both are fully implemented and tested. You can switch between them at any time from the settings
page.

## How do I rotate the client secret?

Entra secrets expire — 24 months at most, and 6 months is the default. Rotation is deliberately
boring:

1. In your app registration, **Certificates & secrets → New client secret**. The old one keeps
   working until it expires, so there is no outage window.
2. Copy the new secret **Value** (not the Secret ID).
3. Paste it into **Settings → Missivus → Client secret** and click **Save Changes**. If you keep
   credentials in `wp-config.php` instead, change the constant there.
4. Press **Send test email** to confirm.
5. Delete the old secret in Entra.

Missivus caches the *access token*, not the secret, for at most 55 minutes — so the changeover is
immediate for new tokens and nothing needs restarting.

## What happens to the From address?

Application-only Graph sends as `/users/{sender}` and Exchange rejects a mismatched From, so the
configured shared mailbox always wins. When a plugin or theme asked for a different From — by
header or by the `wp_mail_from` filter — the requested address is kept as Reply-To (unless an
explicit Reply-To was set) and a warning naming both addresses goes to the log. Set your site's
From address to the shared mailbox and the warning goes away.

## What happens with attachments over 3 MB?

They still send. Graph's inline attachment limit is 3 MB, and the whole `sendMail` request is bounded
at 4 MB, so above that ceiling Missivus automatically switches to Graph's large-file path: it creates
a draft, uploads each large file in chunks through an upload session, then sends the draft. The
decision is made per message and is total-aware, so several medium files that add up also take the
safe path.

There is no setting for this and no way to configure it wrongly.

## Why does the guide ask for Mail.ReadWrite as well as Mail.Send?

Only for that large-attachment path. Creating a draft message and opening an attachment upload
session are not covered by `Mail.Send`; Microsoft requires `Mail.ReadWrite` for them.

If you never send attachments over 3 MB you can grant `Mail.Send` alone. If you do, and
`Mail.ReadWrite` is missing, the failure is loud and names the permission rather than being
mysterious.

Both permissions are scoped by the same application access policy, so `Mail.ReadWrite` grants nothing
outside the one shared mailbox.

## Is the application access policy really necessary?

Yes — treat it as part of the installation, not as hardening you might do later.

Without it, `Mail.Send` as an application permission means the app can send as **any mailbox in your
tenant**. The policy narrows it to one. That single step is what turns "an app that can impersonate
everybody" into "an app that can send as noreply@". The guide gives you the exact commands, including
`Test-ApplicationAccessPolicy` to prove that another mailbox comes back **Denied** before you go any
further.

Note that Exchange scopes a policy to a *group*, not a mailbox, so the guide has you create a
security group whose only member is the shared mailbox. Nobody ever sends to that group; it exists so
the policy has something to point at.

## The test email button is greyed out. What now?

The test sends with the settings that are **saved**, not with what is currently typed on screen, so
the button stays disabled until the stored configuration is complete and Missivus is switched on. The
note under the button says which of those is missing.

Fill in the fields, tick **Send email through Microsoft Graph**, and click **Save Changes**.

## Will Missivus break my email if I install it and do nothing?

No. It ships switched off. Activating the plugin changes nothing at all: `wp_mail()` keeps using
whatever WordPress was already using until you tick **Send email through Microsoft Graph**.
Deactivating the plugin restores the stock mailer with no cleanup.

The optional **fall back to the WordPress mailer** switch is also off by default, deliberately:
a failure you can see beats an email that quietly goes nowhere. Either way, every Graph failure is
written to the PHP error log at error level and announced on the `wp_mail_failed` action.

## Which emails go through Missivus? Do contact-form plugins work?

Anything that calls `wp_mail()` — which is password resets, new-user emails, comment notifications,
WooCommerce order email, and practically every form plugin (Contact Form 7, Gravity Forms, WPForms,
Ninja Forms all use it). Missivus takes over through WordPress's own `pre_wp_mail` seam, before
PHPMailer is even constructed. A plugin that opens its own SMTP connection instead of calling
`wp_mail()` is untouched — and if another mail plugin has already claimed `pre_wp_mail`, Missivus
leaves its answer alone rather than double-sending. Run one mailer plugin at a time.

## Headers my plugin sets — Cc, Bcc, Reply-To, HTML — do they survive?

Yes: From (display name kept, address forced), Reply-To, Cc, Bcc and Content-Type (HTML or plain
text) are all carried over. Other custom headers (`X-…`) are not — Graph's `sendMail` does not
accept arbitrary headers — and each dropped header is named in a log line rather than silently
vanishing.

## I got an error code from Microsoft. What does it mean?

The common ones, in full in the [installation guide's troubleshooting table](INSTALL.md#when-it-does-not-work):

| Code | Usually means |
| --- | --- |
| `AADSTS7000215` | Wrong client secret — often the Secret **ID** was copied instead of the **Value** |
| `AADSTS900023` | Wrong Directory (tenant) ID |
| `AADSTS700027` | The certificate WordPress is using is not the one uploaded to Entra |
| `ErrorAccessDenied` | The application access policy does not cover this mailbox, or admin consent was never granted |
| `ErrorAccessDenied` only on big attachments | `Mail.ReadWrite` is missing |
| `MailboxNotEnabledForRESTAPI` | The sender address is not a real Exchange Online mailbox |
| "mailbox is either inactive, soft-deleted…" | The mailbox has not finished provisioning — wait and retry |

Secrets are redacted before anything is logged or shown, so you can paste these errors into an issue
safely — but do check them over first.

## Does it work on multisite?

Yes, per site: each site has its own settings — or its own constants — and sends as its own
configured mailbox. Network activation works; there is no network-wide settings screen in this
version.

## Where do I report a bug, or a security problem?

Bugs and feature requests: the
[issue tracker](https://github.com/Solvetus/missivus-wordpress/issues).

Security problems: email <security@missivus.com> instead, and please give us a chance to fix it
before it becomes public. [docs/SECURITY.md](SECURITY.md) documents what has already been reviewed.
