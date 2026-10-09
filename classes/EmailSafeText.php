<?php

/**
 * @file classes/EmailSafeText.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class EmailSafeText
 *
 * @brief Makes submitted text safe to put in an email body or header.
 *
 * Kept free of framework dependencies so the two most security-sensitive
 * string operations in the plugin can be unit tested directly.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes;

class EmailSafeText
{
    /** Display names in headers are capped; nothing legitimate needs more. */
    public const MAX_HEADER_NAME_LENGTH = 120;

    /**
     * Make a submitted value safe to substitute into an email template.
     *
     * Three separate hazards, all handled here:
     *
     * 1. The templates are HTML, so the value is HTML-escaped. ENT_SUBSTITUTE
     *    matters: without it, htmlspecialchars() returns an empty string for
     *    malformed UTF-8, which would silently email a blank message while the
     *    archived copy still held the text.
     * 2. Core's Mailer::compileParams() substitutes variables with
     *    preg_replace(), where "$1" and "\1" in a *replacement* are
     *    backreferences, so both characters are escaped.
     * 3. compileParams() applies one pattern after another over the result, so
     *    a value containing "{$contextUrl}" would itself be expanded on a later
     *    pass. Neutralising "{$" keeps submitted text from impersonating a
     *    template variable; "&#36;" renders as "$", so the reader still sees
     *    what was actually written.
     */
    public static function forTemplate(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $value = nl2br($value, false);
        $value = str_replace('{$', '{&#36;', $value);

        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }

    /**
     * Make a value safe to place in an email header: no control characters
     * (which would let a submitter append headers of their own) and bounded
     * length.
     *
     * @return string|null Null when nothing usable is left, so callers can
     *  omit the display name rather than send an empty one.
     */
    public static function forHeader(string $value): ?string
    {
        // No /u modifier: on malformed UTF-8 that would return null and throw
        // the value away, and this has to work on whatever bytes arrived.
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::MAX_HEADER_NAME_LENGTH);
    }
}
