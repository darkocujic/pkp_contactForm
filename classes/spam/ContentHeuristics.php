<?php

/**
 * @file classes/spam/ContentHeuristics.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ContentHeuristics
 *
 * @brief Cheap content checks: length caps, link counts, blocked words and
 *  mail-header injection markers.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes\spam;

class ContentHeuristics
{
    /**
     * Hard ceilings, independent of any setting. The database columns are
     * sized to match, so exceeding one is always a rejection rather than a
     * silent truncation.
     */
    public const MAX_LENGTHS = [
        'name' => 120,
        'email' => 255,
        'subject' => 200,
        'body' => 5000,
    ];

    /** Fields that must never contain a line break. */
    public const SINGLE_LINE_FIELDS = ['name', 'email', 'subject'];

    /**
     * Header names that have no business appearing in a contact message.
     * Deliberately excludes "to:" and "cc:", which occur in ordinary prose.
     */
    private const HEADER_MARKERS = '/(?:^|\s)(bcc|content-type|mime-version|content-transfer-encoding|x-mailer)\s*:/i';

    private int $maxUrls;
    /** @var string[] */
    private array $blockedTerms;
    private string $lastReason = '';

    /**
     * @param int $maxUrls Links permitted in the message. Negative disables the check.
     * @param string[] $blockedTerms Lower-cased terms; any occurrence rejects.
     */
    public function __construct(int $maxUrls = 2, array $blockedTerms = [])
    {
        $this->maxUrls = $maxUrls;
        $this->blockedTerms = $blockedTerms;
    }

    /**
     * Turn the newline-separated setting into a list of terms.
     *
     * @return string[]
     */
    public static function parseBlocklist(string $raw): array
    {
        $terms = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $term = trim(mb_strtolower($line));
            if ($term !== '') {
                $terms[$term] = $term;
            }
        }
        return array_values($terms);
    }

    /**
     * @param array<string, string> $fields Keys: name, email, subject, body.
     */
    public function check(array $fields): bool
    {
        $this->lastReason = '';

        foreach (self::MAX_LENGTHS as $field => $maxLength) {
            $value = (string) ($fields[$field] ?? '');
            if (mb_strlen($value) > $maxLength) {
                $this->lastReason = "{$field} exceeds {$maxLength} characters";
                return false;
            }
        }

        foreach (self::SINGLE_LINE_FIELDS as $field) {
            $value = (string) ($fields[$field] ?? '');
            if (preg_match('/[\r\n]/', $value)) {
                $this->lastReason = "{$field} contains a line break";
                return false;
            }
        }

        foreach ($fields as $field => $value) {
            if (is_string($value) && preg_match(self::HEADER_MARKERS, $value)) {
                $this->lastReason = "{$field} contains a mail header marker";
                return false;
            }
        }

        if ($this->maxUrls >= 0) {
            $urls = $this->countUrls(
                ((string) ($fields['subject'] ?? '')) . "\n" . ((string) ($fields['body'] ?? ''))
            );
            if ($urls > $this->maxUrls) {
                $this->lastReason = "contains {$urls} links, maximum is {$this->maxUrls}";
                return false;
            }
        }

        if ($this->blockedTerms !== []) {
            $haystack = mb_strtolower(implode("\n", array_map(
                fn ($value) => is_string($value) ? $value : '',
                $fields
            )));
            foreach ($this->blockedTerms as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    $this->lastReason = 'contains a blocked term';
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Count things that look like links.
     *
     * Only explicit schemes and "www." are counted. Matching bare domains
     * would flag ordinary sentences that happen to mention a journal title.
     */
    public function countUrls(string $text): int
    {
        return (int) preg_match_all('~(?:https?|ftp)://|\bwww\.[a-z0-9-]~i', $text);
    }

    /**
     * Why the last check() failed. For the error log only — the visitor always
     * sees the same generic message.
     */
    public function getLastReason(): string
    {
        return $this->lastReason;
    }
}
