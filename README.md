# Contact Form plugin for OJS 3.4

A public "Contact us" page for each journal in an OJS 3.4 installation: a visitor fills in a short
form, the journal receives an email, and the message is archived in the database so nothing is lost
if mail delivery fails.

The form is defended by four independent layers — a reCAPTCHA challenge (reusing the installation's
existing configuration), a honeypot field with a signed timing check, content heuristics, and a
per-IP rate limit — so it is usable on a journal that is actively targeted by spam bots.

## Requirements

- OJS **3.4.x**
- PHP **8.0 or newer** (developed and tested on 8.2)
- No Composer dependencies, no build step

## Installation

### From a release tarball (recommended)

1. In OJS, go to **Dashboard → Settings → Website → Plugins → Upload A New Plugin**.
2. Upload `contactForm-<version>.tar.gz`.
3. Enable **Contact Form** under _Generic Plugins_.

Uploading creates the `contact_form_submissions` table automatically. Re-uploading a newer tarball
upgrades in place; the migration is idempotent and your settings are preserved.

### From source

```sh
cd /path/to/ojs/plugins/generic
git clone https://github.com/darkocujic/contactForm.git contactForm
```

The directory **must** be named `contactForm` — OJS derives the plugin class name from it.

## Configuration

Enable the plugin, then use its two actions in the plugin list:

- **Settings** — recipient, page address, page text and all anti-spam thresholds.
- **Messages** — the archive of received messages, newest first.

### Settings

| Setting                                      | Default                     | Notes                                                                                |
| -------------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------ |
| Page address                                 | `contactForm`               | URL segment, e.g. `/index.php/myjournal/contactForm`. Letters and digits only.       |
| Page title / Introduction                    | —                           | Per-locale; shown above the form.                                                    |
| Recipient email                              | journal's principal contact | Where messages are delivered.                                                        |
| Recipient name                               | journal's contact name      | —                                                                                    |
| CC addresses                                 | —                           | Comma-, semicolon- or whitespace-separated; invalid entries are dropped, maximum 10. |
| Require a subject                            | on                          | —                                                                                    |
| Send an acknowledgement                      | **off**                     | See _Acknowledgements_ below before enabling.                                        |
| Minimum / maximum seconds                    | 3 / 7200                    | How fast, and how stale, a submission may be.                                        |
| Maximum links                                | 2                           | Submissions with more URLs in the message are rejected.                              |
| Blocked words                                | —                           | One term per line, matched case-insensitively.                                       |
| Maximum per hour (per visitor / per journal) | 5 / 50                      | Rate limits.                                                                         |
| Keep messages for                            | 365 days                    | `0` keeps them forever.                                                              |

### Adding the link to your navigation

The plugin registers a **Contact form** navigation menu item type. Add it under
**Settings → Website → Navigation** and place it wherever you want; the URL is filled in for you.

### reCAPTCHA

The plugin reuses the reCAPTCHA v2 configuration that OJS already uses for registration, so there
are no keys to enter in the plugin. In `config.inc.php`:

```ini
[captcha]
recaptcha = On
recaptcha_public_key = "your-site-key"
recaptcha_private_key = "your-secret-key"
```

If reCAPTCHA is off, or the keys are missing, the challenge is simply not shown — the form keeps
working with its other three defences. This is deliberate: a misconfigured captcha must not take the
contact page down.

### Acknowledgements

The acknowledgement email goes to whatever address the visitor typed, which means enabling it lets
anyone cause your journal to send mail to a third party. It is therefore **off by default**, it is
counted against the rate limits, and its body deliberately contains none of the visitor's text. Only
turn it on if you accept that trade-off.

## How the anti-spam layers work

- **reCAPTCHA v2** — validated server-side through OJS's own `FormValidatorReCaptcha`.
- **Honeypot** — a decoy field with a plausible, randomly chosen name that is hidden from sighted
  users and from screen readers. Bots that fill in every field are rejected. The name is generated
  once per journal and stored, so caches and already-open forms stay valid.
- **Timing** — the render time travels in a hidden field signed with a per-journal secret, so a
  submission that arrives implausibly fast, implausibly late, or with a forged timestamp is
  rejected. Unlike a session-based timer this survives session loss and page caching.
- **Content heuristics** — link counts, a blocked-word list, hard length caps on every field, and
  rejection of mail-header injection markers.
- **Rate limiting** — counted from the stored messages against a per-journal secret-keyed hash of
  the visitor's address.

All five report the _same_ generic error to the visitor. Telling a bot which check it tripped is
free tuning advice; genuine mistakes (a missing name, a malformed address) still get a specific,
helpful message.

### Rate limiting depends on `trust_x_forwarded_for`

OJS resolves the visitor's address with `PKPRequest::getRemoteAddr()`, which **trusts the
`X-Forwarded-For` header by default** (`general.trust_x_forwarded_for` defaults to on). If your
installation is _not_ behind a reverse proxy that sets that header itself, a client can send any
value it likes and so defeat the per-visitor limit — and the same applies to the address OJS hands
to reCAPTCHA.

If OJS is reached directly, set this in `config.inc.php`:

```ini
[general]
trust_x_forwarded_for = Off
```

The per-journal limit is unaffected either way, so total volume stays capped regardless.

## Privacy

Stored messages contain the name, email address and message text the visitor submitted. The
visitor's IP address is **never** stored — only an HMAC-SHA256 of it, keyed with a per-journal
secret, which is used for rate limiting and cannot be reversed or correlated across journals.

Set **Keep messages for** to a period your privacy policy supports, and mention the contact form in
the journal's privacy statement. Expired messages are deleted by a scheduled task, with a
once-a-day fallback purge for installations that do not run `acron` or cron.

## Emails

Two email templates are installed and can be edited per locale under
**Settings → Workflow → Emails**:

- `CONTACT_FORM_NOTIFICATION` — sent to the journal.
- `CONTACT_FORM_ACKNOWLEDGEMENT` — sent to the visitor, when enabled.

Notifications are sent **from** the journal's contact address (so SPF/DKIM still pass) with the
visitor in **Reply-To**, rather than forging the visitor as the sender.

## Development

```sh
php -l $(git ls-files '*.php')            # with a PHP 8.1+ binary
php-cs-fixer fix --dry-run --diff          # PKP's rule set, inlined in .php-cs-fixer.dist.php
phpunit                                    # unit tests for the anti-spam guards
bash tools/build-release.sh                # produces contactForm-<version>.tar.gz
```

The unit tests cover the guard classes only and need no OJS bootstrap, so they run anywhere.

### Notes for future maintenance

- OJS 3.5 replaces reCAPTCHA with ALTCHA and reworks mailables. The captcha is isolated to a single
  `addCheck()` call in `classes/form/ContactForm.php` to keep that port cheap.

## License

GNU General Public License v3. See `LICENSE`.
