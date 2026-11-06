<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Hydration;

use Maispace\MaiTranslate\Hydration\OverlayFieldDiff;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OverlayFieldDiffTest extends TestCase
{
    #[Test]
    public function emptyOverlayNeedsTranslation(): void
    {
        self::assertSame(
            OverlayFieldDiff::REASON_EMPTY,
            OverlayFieldDiff::reason('Was ist das?', '', [], 'question'),
        );
    }

    #[Test]
    public function copiedGermanTextNeedsTranslationWhenSnapshotIsMissing(): void
    {
        self::assertSame(
            OverlayFieldDiff::REASON_STILL_GERMAN,
            OverlayFieldDiff::reason('Was ist das?', 'Was ist das?', [], 'question'),
        );
    }

    #[Test]
    public function existingTranslationWithoutSnapshotIsLeftAlone(): void
    {
        self::assertNull(
            OverlayFieldDiff::reason('Was ist das?', 'What is that?', [], 'question'),
        );
    }

    #[Test]
    public function changedSourceNeedsTranslation(): void
    {
        self::assertSame(
            OverlayFieldDiff::REASON_SOURCE_CHANGED,
            OverlayFieldDiff::reason('Neue Frage', 'What is that?', ['question' => 'Was ist das?'], 'question'),
        );
    }

    #[Test]
    public function matchingSnapshotSkipsTranslation(): void
    {
        self::assertNull(
            OverlayFieldDiff::reason('Was ist das?', 'What is that?', ['question' => 'Was ist das?'], 'question'),
        );
    }

    #[Test]
    public function emptySourceIsSkipped(): void
    {
        self::assertNull(OverlayFieldDiff::reason('', '', [], 'question'));
    }

    #[Test]
    public function decodeIgnoresInvalidJson(): void
    {
        self::assertSame([], OverlayFieldDiff::decode('not-json'));
        self::assertSame(['title' => 'Alt'], OverlayFieldDiff::decode('{"title":"Alt"}'));
    }

    #[Test]
    public function withSourceValuesMarksOnlyRealChanges(): void
    {
        $updated = OverlayFieldDiff::withSourceValues(['title' => 'Alt'], ['title' => 'Alt', 'subtitle' => 'Neu']);

        self::assertTrue($updated['changed']);
        self::assertSame('Neu', $updated['snapshot']['subtitle']);
    }

    #[Test]
    public function charactersCountUnicode(): void
    {
        self::assertSame(5, OverlayFieldDiff::characters('Äpfel'));
        self::assertNotSame(strlen('Äpfel'), OverlayFieldDiff::characters('Äpfel'));
    }
}
