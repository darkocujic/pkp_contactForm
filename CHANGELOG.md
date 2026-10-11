# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Version numbers
follow PKP's own plugin scheme (`major.minor.revision.build`), not Semantic
Versioning, to match every other plugin in the OJS ecosystem.

## [Unreleased]

## [1.0.6.0] - 2026-10-11

### Added

- A **Store submitted messages** setting. Turning it off means no message
  content — name, email address, subject, body, user agent — is ever
  written to the database; the journal still gets the notification email
  as usual. Rate limiting keeps working regardless: it's now backed by
  its own table holding nothing but a hashed IP and a timestamp, purged
  within a couple of hours, decoupled from the message archive entirely.

## [1.0.5.0] - 2026-10-11

### Fixed

- Opening an email template under Settings > Workflow > Emails could
  return a 500 error (`summarize(): Argument #1 ($item) must be of type
  EmailTemplate, null given`) for this plugin's notification or
  acknowledgement template. OJS only creates a plugin's email templates
  in the database by running its installer, which — like the submissions
  table issue fixed in 1.0.3.0 — doesn't happen on every deployment path.
  The plugin now checks for and creates its own email templates on first
  use, the same way it already does for its database table.

### Changed

- Made `LICENSE` self-contained with the full GPL v3 text, instead of
  pointing to `docs/COPYING` from an OJS install — this standalone repo
  doesn't ship that file. Also corrected the copyright notice, which had
  incorrectly attributed Simon Fraser University / John Willinsky; this
  plugin contains no copied PKP source, so copyright belongs to its own
  authors, as every individual file header already said.
- Added `.github/workflows/release.yml` and `CHANGELOG.md` itself: every
  version bump now publishes a GitHub Release with that version's
  changelog section attached, tarball included.

## [1.0.4.0] - 2026-10-10

### Changed

- Reworked the public contact form's markup so field errors and the
  "message sent" / "could not be sent" banners are styled by whichever
  OJS theme is active, instead of rendering as plain unstyled text.

## [1.0.3.0] - 2026-10-10

### Fixed

- The `contact_form_submissions` table could end up missing after
  upgrading the plugin by re-uploading a newer tarball, or after
  deploying it by copying files directly onto the server — OJS only
  re-runs a plugin's installer on upgrade if it ships an `upgrade.xml`,
  which this plugin didn't yet have. Added one, and the plugin now also
  checks for and creates its own table on first use as a second line of
  defence, regardless of how it was deployed.

## [1.0.2.0] - 2026-10-10

### Fixed

- The contact-form acknowledgement email could show a literal, unresolved
  `{$contextSignature}` placeholder instead of the journal's name —
  `contextSignature` is never actually populated by OJS 3.4's own mail
  variable system, so that reference was removed. `{$contextName}` is now
  substituted by the plugin itself directly, rather than relying solely on
  OJS to fill it in.
- Corrected the plugin's own release-number format, which had briefly
  been `1.0.1` instead of the four-segment `1.0.1.0` every PKP plugin
  uses (see 1.0.1 below).

## [1.0.1] - 2026-10-09

### Security

- Hardened how submitted text and the journal's name are inserted into
  outgoing email: fixed HTML-entity encoding on malformed UTF-8 input,
  stopped submitted text from being interpreted as a mail template
  variable or a regex backreference, and re-validated the sender's
  address immediately before using it as a Reply-To header rather than
  trusting an earlier check.

## [1.0.0.0] - 2026-10-09

### Added

- Initial release: a public, journal-level "Contact us" page with a
  spam-resistant message form (reCAPTCHA v2, a honeypot field with a
  signed timing check, content heuristics, and per-IP/per-journal rate
  limiting), message archiving with a configurable retention period, a
  settings panel, an editable notification email to the journal and an
  optional acknowledgement to the visitor, and a scheduled task to purge
  expired messages.
