<?php

/**
 * @file tests/ThrottleTest.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Tests for the submission rate limits.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\tests;

use APP\plugins\generic\contactForm\classes\spam\Throttle;
use PHPUnit\Framework\TestCase;

class ThrottleTest extends TestCase
{
    public function testAllowsSubmissionsBelowBothLimits(): void
    {
        $throttle = new Throttle(5, 50);

        $this->assertTrue($throttle->isAllowed(0, 0));
        $this->assertTrue($throttle->isAllowed(4, 49));
        $this->assertSame('', $throttle->getLastReason());
    }

    public function testRejectsOnceTheClientLimitIsReached(): void
    {
        $throttle = new Throttle(5, 50);

        // Five already stored means this request would be the sixth.
        $this->assertFalse($throttle->isAllowed(5, 10));
        $this->assertStringContainsString('client', $throttle->getLastReason());
    }

    public function testRejectsBeyondTheClientLimit(): void
    {
        $throttle = new Throttle(5, 50);

        $this->assertFalse($throttle->isAllowed(99, 99));
    }

    public function testRejectsOnceTheJournalLimitIsReached(): void
    {
        $throttle = new Throttle(5, 50);

        $this->assertFalse($throttle->isAllowed(0, 50));
        $this->assertStringContainsString('journal', $throttle->getLastReason());
    }

    public function testTheClientLimitIsReportedBeforeTheJournalLimit(): void
    {
        $throttle = new Throttle(5, 50);

        $this->assertFalse($throttle->isAllowed(5, 50));
        $this->assertStringContainsString('client', $throttle->getLastReason());
    }

    public function testAZeroLimitDisablesThatCheck(): void
    {
        $clientOnly = new Throttle(5, 0);
        $this->assertTrue($clientOnly->isAllowed(4, 100000));
        $this->assertFalse($clientOnly->isAllowed(5, 0));

        $journalOnly = new Throttle(0, 50);
        $this->assertTrue($journalOnly->isAllowed(100000, 49));
        $this->assertFalse($journalOnly->isAllowed(0, 50));

        $disabled = new Throttle(0, 0);
        $this->assertTrue($disabled->isAllowed(100000, 100000));
    }

    public function testANegativeLimitAlsoDisablesThatCheck(): void
    {
        $throttle = new Throttle(-1, -1);

        $this->assertTrue($throttle->isAllowed(1000, 1000));
    }

    public function testTheWindowIsOneHourBeforeTheGivenTime(): void
    {
        $now = 1760000000;

        $this->assertSame($now - 3600, Throttle::windowStart($now));
        $this->assertSame(3600, Throttle::WINDOW_SECONDS);
    }
}
