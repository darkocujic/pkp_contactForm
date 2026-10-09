# contactForm — a generic plugin for OJS 3.4

## What this repo is
The repo root **is** the plugin directory. It installs to `plugins/generic/contactForm/`.
`ojs/` is a read-only reference checkout of `pkp/ojs` (docs only) and is gitignored — never ship it.

## Target platform
- OJS **3.4.x** (`stable-3_4_0`). PKP's sources for 3.4 are on the `stable-3_4_0` branch, **not**
  `main`; `ojs/docs/dev/guide/hooks.rst` in this repo is generated from 3.6-dev, so confirm any hook
  signature against `stable-3_4_0` before relying on it.
- PHP **8.2** for development. Avoid syntax newer than 8.1 so the plugin still loads on the 8.0/8.1
  installs OJS 3.4 supports. No Composer dependencies — nothing to build before upload.
- `declare(strict_types=1)` in framework-free classes only (guards, repository, value objects).
  Omit it in subclasses of PKP `Plugin`/`Handler`/`Form`, whose parent signatures are untyped.

## Non-negotiables
1. **Reuse core before writing.** `FormValidatorReCaptcha`, `FormValidatorCSRF`,
   `FormValidatorPost`, `FormValidatorEmail`, `PKP\form\Form`, `Mailable`, `Repo::emailTemplate()`,
   `AjaxModal`, `LinkAction` all exist. A new class needs a reason.
2. **Fail safe, never fail open.** A missing captcha key disables the captcha but keeps the form
   usable; a mail transport error must not lose a stored message; a DB error must not leak a stack
   trace to a visitor.
3. **Never trust a form field.** Validate server-side, cap every length, strip CR/LF before anything
   reaches a mail header, and escape on output. Recipient addresses come from settings only — never
   from the request.
4. **Spam rejections are silent.** Honeypot, timing, heuristic and throttle failures all return one
   generic message. Genuine validation errors (missing name, malformed email) stay specific.
5. **No PII beyond the message.** Store a salted SHA-256 of the IP, never the IP.
6. **Idempotent install.** Guard migrations with `Schema::hasTable()`; the same tarball may be
   uploaded over an existing install.
7. **i18n and a11y always.** No English in PHP or templates — keys in `locale/en/locale.po`
   (3.4 uses PO, not XML). Every input gets a `<label for>`; errors get `role="alert"`.

## Conventions
- Namespace `APP\plugins\generic\contactForm` (and `\classes`, `\classes\form`, `\classes\mailables`,
  `\classes\spam`, `\classes\tasks`), mirroring the directory layout.
- Formatting follows PKP: 4 spaces, PSR-12, one class per file, GPLv3 docblock header naming the
  file path. `.php-cs-fixer.dist.php` carries PKP's rule set, inlined so it works standalone.
- Smarty templates in `templates/`, resolved with `$this->getTemplateResource('x.tpl')`.
- Settings read/written with `$plugin->getSetting($contextId, $name)` / `updateSetting(...)`.

## Local tooling
The system PHP on this machine is 7.4, which cannot parse PHP 8 syntax. Lint with a PHP 8.1+ binary
(a portable build in the session scratchpad is fine) — never conclude a file is clean from a 7.4
`php -l`. CI covers 8.1/8.2/8.3.

## Definition of done for any change
`php -l` clean on every touched file under PHP 8.2; `php-cs-fixer --dry-run` clean; unit tests green;
new strings present in `locale/en/locale.po`; `tools/build-release.sh` still produces an installable
tarball.

## Git
Commit often — one commit per coherent unit of work, not one commit per session. Commit as soon as a
file group is lint-clean, so a bad step can be dropped without unpicking good work.
Short imperative subjects (`Add honeypot guard`, `Fix CC list validation`). Bump `version.xml`'s
`<release>` in its own commit. **No `Co-Authored-By` or generated-with trailers in commit messages.**
