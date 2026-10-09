<?php

/**
 * @file tests/ContentHeuristicsTest.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Tests for the content heuristics.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\tests;

use APP\plugins\generic\contactForm\classes\spam\ContentHeuristics;
use PHPUnit\Framework\TestCase;

class ContentHeuristicsTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.org',
            'subject' => 'A question about your submission policy',
            'body' => "Hello,\n\nCould you clarify the word limit for review articles?\n\nThanks,\nAda",
        ], $overrides);
    }

    public function testAcceptsAnOrdinaryMessage(): void
    {
        $heuristics = new ContentHeuristics();

        $this->assertTrue($heuristics->check($this->fields()));
        $this->assertSame('', $heuristics->getLastReason());
    }

    public function testAcceptsLineBreaksInTheBody(): void
    {
        $heuristics = new ContentHeuristics();

        $this->assertTrue($heuristics->check($this->fields(['body' => "line one\r\nline two\n\nline three"])));
    }

    public function testRejectsEachFieldThatExceedsItsLengthCap(): void
    {
        $heuristics = new ContentHeuristics();

        foreach (ContentHeuristics::MAX_LENGTHS as $field => $maxLength) {
            $atLimit = $this->fields([$field => str_repeat('a', $maxLength)]);
            $this->assertTrue($heuristics->check($atLimit), "{$field} at its limit should pass");

            $overLimit = $this->fields([$field => str_repeat('a', $maxLength + 1)]);
            $this->assertFalse($heuristics->check($overLimit), "{$field} over its limit should fail");
            $this->assertStringContainsString($field, $heuristics->getLastReason());
        }
    }

    public function testMeasuresLengthInCharactersNotBytes(): void
    {
        $heuristics = new ContentHeuristics();
        $maxLength = ContentHeuristics::MAX_LENGTHS['name'];

        // Multi-byte characters must not count double, or non-Latin names would
        // be rejected at half the stated limit.
        $this->assertTrue($heuristics->check($this->fields(['name' => str_repeat('é', $maxLength)])));
        $this->assertFalse($heuristics->check($this->fields(['name' => str_repeat('é', $maxLength + 1)])));
    }

    public function testRejectsLineBreaksInSingleLineFields(): void
    {
        $heuristics = new ContentHeuristics();

        foreach (ContentHeuristics::SINGLE_LINE_FIELDS as $field) {
            foreach (["a\nb", "a\rb", "a\r\nb"] as $value) {
                $this->assertFalse(
                    $heuristics->check($this->fields([$field => $value])),
                    "A line break in {$field} should be rejected"
                );
                $this->assertStringContainsString('line break', $heuristics->getLastReason());
            }
        }
    }

    public function testRejectsMailHeaderInjectionMarkers(): void
    {
        $heuristics = new ContentHeuristics();

        $payloads = [
            'subject' => 'Hi bcc: victim@example.org',
            'body' => "Hello\nContent-Type: text/html",
            'name' => 'Ada mime-version: 1.0',
        ];

        foreach ($payloads as $field => $value) {
            $this->assertFalse(
                $heuristics->check($this->fields([$field => $value])),
                "A header marker in {$field} should be rejected"
            );
            $this->assertStringContainsString('header marker', $heuristics->getLastReason());
        }
    }

    public function testDoesNotFlagOrdinaryColonsInProse(): void
    {
        $heuristics = new ContentHeuristics();

        // "to:" and "cc:" are excluded from the marker list precisely because
        // they appear in normal writing.
        $this->assertTrue($heuristics->check($this->fields([
            'body' => "According to: Smith et al.\nPlease cc: my colleague as well.",
        ])));
    }

    public function testCountsLinks(): void
    {
        $heuristics = new ContentHeuristics();

        $this->assertSame(0, $heuristics->countUrls('no links at all'));
        $this->assertSame(1, $heuristics->countUrls('see https://example.org'));
        $this->assertSame(2, $heuristics->countUrls('https://a.example and http://b.example'));
        $this->assertSame(1, $heuristics->countUrls('visit www.example.org'));
        $this->assertSame(1, $heuristics->countUrls('ftp://files.example.org'));
    }

    public function testDoesNotCountBareDomainsAsLinks(): void
    {
        $heuristics = new ContentHeuristics();

        // Journal titles and abbreviations contain dots; counting them would
        // reject legitimate messages.
        $this->assertSame(0, $heuristics->countUrls('Published in J.Chem.Phys. last year.'));
    }

    public function testRejectsMessagesWithTooManyLinks(): void
    {
        $heuristics = new ContentHeuristics(2);

        $this->assertTrue($heuristics->check($this->fields([
            'body' => 'https://a.example and https://b.example',
        ])));

        $this->assertFalse($heuristics->check($this->fields([
            'body' => 'https://a.example https://b.example https://c.example',
        ])));
        $this->assertStringContainsString('links', $heuristics->getLastReason());
    }

    public function testCountsLinksInTheSubjectToo(): void
    {
        $heuristics = new ContentHeuristics(0);

        $this->assertFalse($heuristics->check($this->fields([
            'subject' => 'Visit https://spam.example',
            'body' => 'Nothing here.',
        ])));
    }

    public function testANegativeLinkLimitDisablesTheCheck(): void
    {
        $heuristics = new ContentHeuristics(-1);

        $this->assertTrue($heuristics->check($this->fields([
            'body' => 'https://a.example https://b.example https://c.example https://d.example',
        ])));
    }

    public function testParsesTheBlocklistSetting(): void
    {
        $terms = ContentHeuristics::parseBlocklist("  Viagra \n\ncheap PILLS\r\nviagra\n   \n");

        $this->assertSame(['viagra', 'cheap pills'], $terms);
    }

    public function testParsesAnEmptyBlocklist(): void
    {
        $this->assertSame([], ContentHeuristics::parseBlocklist(''));
        $this->assertSame([], ContentHeuristics::parseBlocklist("\n \n"));
    }

    public function testRejectsBlockedTermsRegardlessOfCase(): void
    {
        $heuristics = new ContentHeuristics(2, ContentHeuristics::parseBlocklist('casino'));

        $this->assertFalse($heuristics->check($this->fields(['body' => 'Try our CASINO today'])));
        $this->assertStringContainsString('blocked term', $heuristics->getLastReason());

        $this->assertTrue($heuristics->check($this->fields(['body' => 'Nothing objectionable here'])));
    }

    public function testChecksEveryFieldAgainstTheBlocklist(): void
    {
        $heuristics = new ContentHeuristics(2, ['casino']);

        foreach (['name', 'subject', 'body'] as $field) {
            $this->assertFalse(
                $heuristics->check($this->fields([$field => 'casino'])),
                "A blocked term in {$field} should be rejected"
            );
        }
    }

    public function testTreatsMissingFieldsAsEmpty(): void
    {
        $heuristics = new ContentHeuristics();

        $this->assertTrue($heuristics->check([]));
    }
}
