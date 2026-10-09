<?php

/**
 * @file tests/EmailSafeTextTest.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Tests for the email body and header sanitisers.
 */

declare(strict_types=1);

namespace APP\plugins\generic\contactForm\tests;

use APP\plugins\generic\contactForm\classes\EmailSafeText;
use PHPUnit\Framework\TestCase;

class EmailSafeTextTest extends TestCase
{
    public function testLeavesOrdinaryTextAlone(): void
    {
        $this->assertSame(
            'Could you clarify the word limit?',
            EmailSafeText::forTemplate('Could you clarify the word limit?')
        );
    }

    public function testEscapesHtml(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            EmailSafeText::forTemplate('<script>alert(1)</script>')
        );
    }

    public function testEscapesQuotes(): void
    {
        $value = EmailSafeText::forTemplate('say "hi" and \'bye\'');

        $this->assertStringNotContainsString('"', $value);
        $this->assertStringNotContainsString("'", $value);
    }

    public function testConvertsNewlinesToBreaksWithoutRemovingThem(): void
    {
        $value = EmailSafeText::forTemplate("one\ntwo");

        $this->assertSame("one<br>\ntwo", $value);
    }

    public function testKeepsMalformedUtf8InsteadOfReturningNothing(): void
    {
        // Without ENT_SUBSTITUTE, htmlspecialchars() returns '' here, which
        // would email a blank message while the archive still held the text.
        $value = EmailSafeText::forTemplate("valid \xB1\x31 text");

        $this->assertNotSame('', $value);
        $this->assertStringContainsString('valid', $value);
        $this->assertStringContainsString('text', $value);
    }

    public function testNeutralisesTemplateVariableSyntax(): void
    {
        // Core substitutes variables one pattern after another over the same
        // string, so a submitted "{$contextUrl}" would be expanded on a later
        // pass if it survived intact.
        $value = EmailSafeText::forTemplate('see {$contextUrl} and {$passwordLostUrl}');

        $this->assertStringNotContainsString('{$', $value);
        $this->assertStringContainsString('{&#36;contextUrl}', $value);
        $this->assertStringContainsString('{&#36;passwordLostUrl}', $value);
    }

    public function testEscapesRegexReplacementBackreferences(): void
    {
        // preg_replace() reads "$1" and "\1" in a replacement as references to
        // captured groups, so both characters have to arrive escaped.
        $this->assertSame('\\$1 and \\\\1', EmailSafeText::forTemplate('$1 and \\1'));
    }

    public function testSubstitutingAnEscapedValueProducesTheOriginalText(): void
    {
        // The round trip core performs: our value is used as a preg_replace
        // replacement, so what the recipient reads must match what was typed.
        $submitted = 'Pay $100 to {$contextName}\\1 — see <b>below</b>';

        $rendered = preg_replace(
            '/\{\$messageBody\}/',
            EmailSafeText::forTemplate($submitted),
            '<p>{$messageBody}</p>'
        );

        $this->assertSame(
            '<p>Pay $100 to {&#36;contextName}\\1 — see &lt;b&gt;below&lt;/b&gt;</p>',
            $rendered
        );
    }

    public function testHeaderValuePassesThroughWhenClean(): void
    {
        $this->assertSame('Ada Lovelace', EmailSafeText::forHeader('Ada Lovelace'));
    }

    public function testHeaderValueStripsControlCharacters(): void
    {
        // The classic header injection attempt.
        $this->assertSame(
            'Ada Bcc: victim@example.org',
            EmailSafeText::forHeader("Ada\r\nBcc: victim@example.org")
        );
    }

    public function testHeaderValueStripsNullBytesAndTabs(): void
    {
        $this->assertSame('a b', EmailSafeText::forHeader("a\x00\x09b"));
    }

    public function testHeaderValueIsTrimmed(): void
    {
        $this->assertSame('Ada', EmailSafeText::forHeader("  Ada \r\n"));
    }

    public function testHeaderValueIsNullWhenNothingUsableRemains(): void
    {
        $this->assertNull(EmailSafeText::forHeader(''));
        $this->assertNull(EmailSafeText::forHeader("   \r\n\t"));
        $this->assertNull(EmailSafeText::forHeader("\x00"));
    }

    public function testHeaderValueIsCapped(): void
    {
        $value = EmailSafeText::forHeader(str_repeat('a', 500));

        $this->assertSame(EmailSafeText::MAX_HEADER_NAME_LENGTH, strlen((string) $value));
    }

    public function testHeaderValueCapsByCharactersNotBytes(): void
    {
        $value = (string) EmailSafeText::forHeader(str_repeat('é', 500));

        $this->assertSame(EmailSafeText::MAX_HEADER_NAME_LENGTH, mb_strlen($value));
    }

    public function testHeaderValueSurvivesMalformedUtf8(): void
    {
        // No /u modifier in the sanitiser, so malformed bytes must not cause
        // the whole display name to be discarded.
        $this->assertNotNull(EmailSafeText::forHeader("Ada \xB1\x31"));
    }
}
