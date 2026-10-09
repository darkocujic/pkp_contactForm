<?php

/**
 * @file classes/spam/HoneypotGuard.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class HoneypotGuard
 *
 * @brief Decoy field and signed-timestamp checks for the contact form.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes\spam;

class HoneypotGuard
{
    /** Name of the hidden field carrying the signed render time. */
    public const TIMESTAMP_FIELD = 'cfRenderedAt';

    /** Tolerance for a timestamp that appears to be in the future (clock skew). */
    public const FUTURE_TOLERANCE_SECONDS = 60;

    /** Word lists used to invent a decoy field name a bot will want to fill. */
    private const NAME_PREFIXES = ['your', 'contact', 'web', 'home', 'company', 'business', 'personal', 'alternate', 'other'];
    private const NAME_SUBJECTS = ['site', 'page', 'url', 'link', 'address', 'website', 'homepage', 'profile', 'blog', 'domain'];
    private const NAME_SUFFIXES = ['', 'field', 'input', 'value', 'info', 'details'];

    private string $fieldName;
    private string $secret;
    private int $minSeconds;
    private int $maxSeconds;
    private string $lastReason = '';

    /**
     * @param string $fieldName Name of the decoy field, from plugin settings.
     * @param string $secret Per-journal secret used to sign the render time.
     * @param int $minSeconds Reject submissions faster than this. 0 disables.
     * @param int $maxSeconds Reject submissions older than this. 0 disables.
     */
    public function __construct(string $fieldName, string $secret, int $minSeconds = 3, int $maxSeconds = 7200)
    {
        $this->fieldName = $fieldName;
        $this->secret = $secret;
        $this->minSeconds = max(0, $minSeconds);
        $this->maxSeconds = max(0, $maxSeconds);
    }

    /**
     * Invent a plausible-looking decoy field name.
     *
     * Generated once per journal and then stored, so that cached pages and
     * forms a visitor already has open keep working.
     */
    public static function generateFieldName(): string
    {
        $parts = [
            self::NAME_PREFIXES[random_int(0, count(self::NAME_PREFIXES) - 1)],
            self::NAME_SUBJECTS[random_int(0, count(self::NAME_SUBJECTS) - 1)],
            self::NAME_SUFFIXES[random_int(0, count(self::NAME_SUFFIXES) - 1)],
        ];

        $name = implode('_', array_filter($parts, fn ($part) => $part !== ''));

        // Belt and braces: the caller validates against this pattern too.
        return preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) ? $name : 'your_website';
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    /**
     * The value for the hidden timestamp field: the render time plus an HMAC
     * of it.
     *
     * Signing the time instead of storing it in the session means the check
     * survives session loss, load balancing and full-page caching.
     */
    public function createTimestampToken(?int $now = null): string
    {
        $now = $now ?? time();
        return $now . '.' . $this->sign((string) $now);
    }

    /**
     * Did this submission come from a real visitor using our form?
     *
     * @param array<string, mixed> $input Raw request values.
     * @param int|null $now Injectable for tests.
     */
    public function check(array $input, ?int $now = null): bool
    {
        $now = $now ?? time();
        $this->lastReason = '';

        if ($this->isDecoyFilled($input)) {
            $this->lastReason = 'honeypot field was filled';
            return false;
        }

        $timestamp = $this->readTimestamp($input);
        if ($timestamp === null) {
            // The token is emitted by our own template, so a missing or forged
            // one means the POST did not come from a form we rendered.
            $this->lastReason = 'missing or forged timestamp token';
            return false;
        }

        $elapsed = $now - $timestamp;

        if ($elapsed < -self::FUTURE_TOLERANCE_SECONDS) {
            $this->lastReason = 'timestamp is in the future';
            return false;
        }

        if ($this->minSeconds > 0 && $elapsed < $this->minSeconds) {
            $this->lastReason = "submitted after {$elapsed}s, minimum is {$this->minSeconds}s";
            return false;
        }

        if ($this->maxSeconds > 0 && $elapsed > $this->maxSeconds) {
            $this->lastReason = "submitted after {$elapsed}s, maximum is {$this->maxSeconds}s";
            return false;
        }

        return true;
    }

    /**
     * Why the last check() failed. For the error log only — the visitor always
     * sees the same generic message.
     */
    public function getLastReason(): string
    {
        return $this->lastReason;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isDecoyFilled(array $input): bool
    {
        if (!array_key_exists($this->fieldName, $input)) {
            return false;
        }

        $value = $input[$this->fieldName];
        if (is_array($value)) {
            // Some bots post every field as an array.
            $value = implode('', array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $value));
        } elseif (!is_scalar($value)) {
            return true;
        }

        return trim((string) $value) !== '';
    }

    /**
     * Extract the render time from a valid token, or null.
     *
     * @param array<string, mixed> $input
     */
    private function readTimestamp(array $input): ?int
    {
        $token = $input[self::TIMESTAMP_FIELD] ?? null;
        if (!is_string($token) || !str_contains($token, '.')) {
            return null;
        }

        [$timestamp, $signature] = explode('.', $token, 2);
        if (!preg_match('/^[0-9]{1,12}$/', $timestamp)) {
            return null;
        }

        if (!hash_equals($this->sign($timestamp), $signature)) {
            return null;
        }

        return (int) $timestamp;
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha256', $value, $this->secret);
    }
}
