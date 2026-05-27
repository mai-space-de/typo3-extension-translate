<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Service;

use Maispace\MaiTranslate\Service\TranslationLengthGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationLengthGuardTest extends TestCase
{
    private TranslationLengthGuard $subject;

    protected function setUp(): void
    {
        $this->subject = new TranslationLengthGuard();
    }

    // ── withinLimit() ─────────────────────────────────────────────────────────

    #[Test]
    public function withinLimitTreatsZeroAsNoLimit(): void
    {
        self::assertTrue($this->subject->withinLimit('any length string', TranslationLengthGuard::NO_LIMIT));
    }

    #[Test]
    public function withinLimitTreatsNegativeAsNoLimit(): void
    {
        self::assertTrue($this->subject->withinLimit('any length string', -10));
    }

    #[Test]
    public function withinLimitIsTrueWhenTextIsShorterThanLimit(): void
    {
        self::assertTrue($this->subject->withinLimit('Hallo', 10));
    }

    #[Test]
    public function withinLimitIsTrueWhenTextLengthEqualsLimit(): void
    {
        self::assertTrue($this->subject->withinLimit('Hallo', 5));
    }

    #[Test]
    public function withinLimitIsFalseWhenTextExceedsLimit(): void
    {
        self::assertFalse($this->subject->withinLimit('Hallo Welt', 5));
    }

    #[Test]
    public function withinLimitCountsMultibyteCharactersNotBytes(): void
    {
        // "Привіт" is 6 Unicode characters but 12 bytes in UTF-8.
        self::assertTrue($this->subject->withinLimit('Привіт', 6));
        self::assertFalse($this->subject->withinLimit('Привіт', 5));
    }

    // ── enforce() ─────────────────────────────────────────────────────────────

    #[Test]
    public function enforceReturnsTextUnchangedWhenWithinLimit(): void
    {
        self::assertSame('Hallo Welt', $this->subject->enforce('Hallo Welt', 20));
    }

    #[Test]
    public function enforceReturnsTextUnchangedWhenLimitIsDisabled(): void
    {
        self::assertSame('Hallo Welt', $this->subject->enforce('Hallo Welt', TranslationLengthGuard::NO_LIMIT));
    }

    #[Test]
    public function enforceTruncatesOnTheLastWordBoundaryWithinTheLimit(): void
    {
        // Window "Hallo Wo" → last whole word is "Hallo".
        self::assertSame('Hallo', $this->subject->enforce('Hallo Welt', 8));
    }

    #[Test]
    public function enforceHardCutsASingleWordLongerThanTheLimit(): void
    {
        self::assertSame('Inter', $this->subject->enforce('Internationalisierung', 5));
    }

    #[Test]
    public function enforceNeverReturnsMoreCharactersThanTheLimit(): void
    {
        $result = $this->subject->enforce('Dies ist ein langer deutscher Satz', 12);

        self::assertLessThanOrEqual(12, mb_strlen($result));
    }

    #[Test]
    public function enforceTrimsTrailingWhitespaceFromTheCut(): void
    {
        // Window of 6 chars is "Hallo " → trailing space removed, no inner boundary kept.
        self::assertSame('Hallo', $this->subject->enforce('Hallo Welt', 6));
    }

    #[Test]
    public function enforceIsMultibyteSafe(): void
    {
        // 6 Cyrillic chars, limit 6 → unchanged; limit 4 → hard cut to 4 chars.
        self::assertSame('Привіт', $this->subject->enforce('Привіт', 6));
        self::assertSame('Прив', $this->subject->enforce('Привіт', 4));
    }
}
