<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Domain\Model;

use Maispace\MaiTranslate\Domain\Model\TranslationLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationLogTest extends TestCase
{
    // ── Constructor / getters ────────────────────────────────────────────────

    #[Test]
    public function getUidReturnsConstructorValue(): void
    {
        $log = new TranslationLog(42, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame(42, $log->getUid());
    }

    #[Test]
    public function getRecordTableReturnsConstructorValue(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame('tt_content', $log->getRecordTable());
    }

    #[Test]
    public function getRecordUidReturnsConstructorValue(): void
    {
        $log = new TranslationLog(1, 'tt_content', 99, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame(99, $log->getRecordUid());
    }

    #[Test]
    public function getFieldReturnsConstructorValue(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'header', 'en', 'de', 'deepl', 'success');
        self::assertSame('header', $log->getField());
    }

    #[Test]
    public function getSourceLanguageReturnsConstructorValue(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame('en', $log->getSourceLanguage());
    }

    #[Test]
    public function getTargetLanguageReturnsConstructorValue(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame('de', $log->getTargetLanguage());
    }

    #[Test]
    public function getProviderReturnsDeepL(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame('deepl', $log->getProvider());
    }

    #[Test]
    public function getProviderReturnsOpenAi(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'openai', 'success');
        self::assertSame('openai', $log->getProvider());
    }

    #[Test]
    public function getStatusReturnsSuccess(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertSame('success', $log->getStatus());
    }

    #[Test]
    public function getStatusReturnsFailed(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'failed');
        self::assertSame('failed', $log->getStatus());
    }

    // ── isSuccess() ──────────────────────────────────────────────────────────

    #[Test]
    public function isSuccessReturnsTrueForSuccessStatus(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
        self::assertTrue($log->isSuccess());
    }

    #[Test]
    public function isSuccessReturnsFalseForFailedStatus(): void
    {
        $log = new TranslationLog(1, 'tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'failed');
        self::assertFalse($log->isSuccess());
    }

    // ── Instance isolation ───────────────────────────────────────────────────

    #[Test]
    public function twoInstancesAreIndependent(): void
    {
        $logA = new TranslationLog(1, 'tt_content', 10, 'bodytext', 'en', 'de', 'deepl', 'success');
        $logB = new TranslationLog(2, 'pages', 20, 'title', 'de', 'uk', 'openai', 'failed');

        self::assertSame(1, $logA->getUid());
        self::assertSame(2, $logB->getUid());
        self::assertSame('tt_content', $logA->getRecordTable());
        self::assertSame('pages', $logB->getRecordTable());
        self::assertTrue($logA->isSuccess());
        self::assertFalse($logB->isSuccess());
    }
}
