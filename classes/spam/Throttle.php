<?php

/**
 * @file classes/spam/Throttle.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Throttle
 *
 * @brief Rate limits, evaluated against counts the caller supplies.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\classes\spam;

class Throttle
{
    /** The window the supplied counts are expected to cover. */
    public const WINDOW_SECONDS = 3600;

    private int $maxPerWindowPerIp;
    private int $maxPerWindowPerContext;
    private string $lastReason = '';

    /**
     * @param int $maxPerWindowPerIp Submissions allowed from one client. 0 or less disables.
     * @param int $maxPerWindowPerContext Submissions allowed for the whole journal. 0 or less disables.
     */
    public function __construct(int $maxPerWindowPerIp = 5, int $maxPerWindowPerContext = 50)
    {
        $this->maxPerWindowPerIp = $maxPerWindowPerIp;
        $this->maxPerWindowPerContext = $maxPerWindowPerContext;
    }

    /**
     * The start of the window the counts should be taken from.
     */
    public static function windowStart(?int $now = null): int
    {
        return ($now ?? time()) - self::WINDOW_SECONDS;
    }

    /**
     * @param int $recentForIp Submissions already stored for this client in the window.
     * @param int $recentForContext Submissions already stored for this journal in the window.
     */
    public function isAllowed(int $recentForIp, int $recentForContext): bool
    {
        $this->lastReason = '';

        if ($this->maxPerWindowPerIp > 0 && $recentForIp >= $this->maxPerWindowPerIp) {
            $this->lastReason = "client reached {$this->maxPerWindowPerIp} submissions per hour";
            return false;
        }

        if ($this->maxPerWindowPerContext > 0 && $recentForContext >= $this->maxPerWindowPerContext) {
            $this->lastReason = "journal reached {$this->maxPerWindowPerContext} submissions per hour";
            return false;
        }

        return true;
    }

    /**
     * Why the last isAllowed() returned false. For the error log only — the
     * visitor always sees the same generic message.
     */
    public function getLastReason(): string
    {
        return $this->lastReason;
    }
}
