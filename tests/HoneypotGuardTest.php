<?php

/**
 * @file tests/HoneypotGuardTest.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Tests for the decoy field and signed-timestamp checks.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\tests;

use APP\plugins\generic\contactForm\classes\spam\HoneypotGuard;
use PHPUnit\Framework\TestCase;

class HoneypotGuardTest extends TestCase
{
    private const SECRET = 'a-secret-that-is-at-least-32-characters-long';
    private const DECOY = 'your_website';
    private const NOW = 1760000000;

    private function guard(int $minSeconds = 3, int $maxSeconds = 7200, string $secret = self::SECRET): HoneypotGuard
    {
        return new HoneypotGuard(self::DECOY, $secret, $minSeconds, $maxSeconds);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(HoneypotGuard $guard, int $renderedAt, array $overrides = []): array
    {
        return array_merge([
            HoneypotGuard::TIMESTAMP_FIELD => $guard->createTimestampToken($renderedAt),
        ], $overrides);
    }

    public function testAcceptsAGenuineSubmission(): void
    {
        $guard = $this->guard();
        $input = $this->input($guard, self::NOW - 30);

        $this->assertTrue($guard->check($input, self::NOW));
        $this->assertSame('', $guard->getLastReason());
    }

    public function testAcceptsAnEmptyDecoyField(): void
    {
        $guard = $this->guard();
        $input = $this->input($guard, self::NOW - 30, [self::DECOY => '']);

        $this->assertTrue($guard->check($input, self::NOW));
    }

    public function testRejectsAFilledDecoyField(): void
    {
        $guard = $this->guard();
        $input = $this->input($guard, self::NOW - 30, [self::DECOY => 'http://spam.example']);

        $this->assertFalse($guard->check($input, self::NOW));
        $this->assertStringContainsString('honeypot', $guard->getLastReason());
    }

    public function testTreatsAWhitespaceOnlyDecoyFieldAsEmpty(): void
    {
        $guard = $this->guard();

        // Whitespace alone is what a browser autofill-then-clear leaves behind,
        // so it must be treated as empty.
        $this->assertTrue($guard->check($this->input($guard, self::NOW - 30, [self::DECOY => "  \t "]), self::NOW));
    }

    public function testRejectsADecoyFieldPostedAsAnArray(): void
    {
        $guard = $this->guard();
        $input = $this->input($guard, self::NOW - 30, [self::DECOY => ['', 'spam']]);

        $this->assertFalse($guard->check($input, self::NOW));
    }

    public function testRejectsAMissingTimestampToken(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->check([], self::NOW));
        $this->assertStringContainsString('timestamp', $guard->getLastReason());
    }

    public function testRejectsAMalformedTimestampToken(): void
    {
        $guard = $this->guard();

        foreach (['', 'nonsense', '12345', 'abc.def', '.', '12345.'] as $token) {
            $this->assertFalse(
                $guard->check([HoneypotGuard::TIMESTAMP_FIELD => $token], self::NOW),
                "Token '{$token}' should be rejected"
            );
        }
    }

    public function testRejectsAForgedSignature(): void
    {
        $guard = $this->guard();
        $renderedAt = self::NOW - 30;
        $input = [HoneypotGuard::TIMESTAMP_FIELD => $renderedAt . '.' . str_repeat('0', 64)];

        $this->assertFalse($guard->check($input, self::NOW));
    }

    public function testRejectsATokenSignedWithAnotherSecret(): void
    {
        $other = $this->guard(3, 7200, 'a-completely-different-secret-value-here');
        $input = $this->input($other, self::NOW - 30);

        $this->assertFalse($this->guard()->check($input, self::NOW));
    }

    public function testRejectsATokenWhoseTimestampHasBeenEdited(): void
    {
        $guard = $this->guard();
        $token = $guard->createTimestampToken(self::NOW - 30);
        [, $signature] = explode('.', $token, 2);

        // Same signature, different time: the HMAC no longer matches.
        $input = [HoneypotGuard::TIMESTAMP_FIELD => (self::NOW - 600) . '.' . $signature];

        $this->assertFalse($guard->check($input, self::NOW));
    }

    public function testRejectsASubmissionThatArrivesTooFast(): void
    {
        $guard = $this->guard(3, 7200);
        $input = $this->input($guard, self::NOW - 1);

        $this->assertFalse($guard->check($input, self::NOW));
        $this->assertStringContainsString('minimum', $guard->getLastReason());
    }

    public function testAcceptsASubmissionExactlyAtTheMinimum(): void
    {
        $guard = $this->guard(3, 7200);

        $this->assertTrue($guard->check($this->input($guard, self::NOW - 3), self::NOW));
    }

    public function testRejectsAStaleSubmission(): void
    {
        $guard = $this->guard(3, 7200);
        $input = $this->input($guard, self::NOW - 7201);

        $this->assertFalse($guard->check($input, self::NOW));
        $this->assertStringContainsString('maximum', $guard->getLastReason());
    }

    public function testAcceptsASubmissionExactlyAtTheMaximum(): void
    {
        $guard = $this->guard(3, 7200);

        $this->assertTrue($guard->check($this->input($guard, self::NOW - 7200), self::NOW));
    }

    public function testRejectsATimestampFromTheFuture(): void
    {
        $guard = $this->guard(0, 0);
        $input = $this->input($guard, self::NOW + HoneypotGuard::FUTURE_TOLERANCE_SECONDS + 10);

        $this->assertFalse($guard->check($input, self::NOW));
        $this->assertStringContainsString('future', $guard->getLastReason());
    }

    public function testToleratesSmallClockSkew(): void
    {
        $guard = $this->guard(0, 0);
        $input = $this->input($guard, self::NOW + 5);

        $this->assertTrue($guard->check($input, self::NOW));
    }

    public function testZeroBoundsDisableTimingButStillRequireAValidToken(): void
    {
        $guard = $this->guard(0, 0);

        $this->assertTrue($guard->check($this->input($guard, self::NOW - 1), self::NOW));
        $this->assertTrue($guard->check($this->input($guard, self::NOW - 999999), self::NOW));
        $this->assertFalse($guard->check([], self::NOW));
    }

    public function testGeneratedFieldNamesAreValidAndVaried(): void
    {
        $names = [];
        for ($i = 0; $i < 50; $i++) {
            $name = HoneypotGuard::generateFieldName();
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{2,63}$/', $name);
            $names[$name] = true;
        }

        $this->assertGreaterThan(1, count($names), 'Field names should not be constant');
    }
}
