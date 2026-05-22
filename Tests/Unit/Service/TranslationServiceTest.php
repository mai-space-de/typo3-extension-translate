<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Service;

use Maispace\MaiTranslate\Provider\TranslationProviderInterface;
use Maispace\MaiTranslate\Service\TranslationLogServiceInterface;
use Maispace\MaiTranslate\Service\TranslationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TranslationServiceTest extends TestCase
{
    private TranslationProviderInterface&MockObject $provider;
    private TranslationLogServiceInterface&MockObject $logService;
    private TranslationService $subject;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(TranslationProviderInterface::class);
        $this->provider->method('getIdentifier')->willReturn('deepl');

        $this->logService = $this->createMock(TranslationLogServiceInterface::class);

        $this->subject = new TranslationService($this->provider, $this->logService);
    }

    // ── translate() — happy path ──────────────────────────────────────────────

    #[Test]
    public function translateReturnsTranslatedText(): void
    {
        $this->provider->method('translate')->willReturn('Hallo Welt');

        $result = $this->subject->translate('Hello World', 'en', 'de');

        self::assertSame('Hallo Welt', $result);
    }

    #[Test]
    public function translateDelegatesToProvider(): void
    {
        $this->provider->expects(self::once())
            ->method('translate')
            ->with('Hello', 'en', 'de')
            ->willReturn('Hallo');

        $this->subject->translate('Hello', 'en', 'de');
    }

    #[Test]
    public function translateLogsSuccessEntryWhenRecordTableIsGiven(): void
    {
        $this->provider->method('translate')->willReturn('Hallo');

        $this->logService->expects(self::once())
            ->method('log')
            ->with('tt_content', 42, 'bodytext', 'en', 'de', 'deepl', 'success');

        $this->subject->translate('Hello', 'en', 'de', 'tt_content', 42, 'bodytext');
    }

    #[Test]
    public function translateSkipsLoggingWhenRecordTableIsEmpty(): void
    {
        $this->provider->method('translate')->willReturn('Hallo');

        $this->logService->expects(self::never())->method('log');

        $this->subject->translate('Hello', 'en', 'de');
    }

    #[Test]
    public function translateSkipsLoggingWhenRecordTableIsEmptyEvenIfUidAndFieldAreGiven(): void
    {
        $this->provider->method('translate')->willReturn('Hallo');

        $this->logService->expects(self::never())->method('log');

        $this->subject->translate('Hello', 'en', 'de', '', 99, 'title');
    }

    #[Test]
    public function translateUsesProviderIdentifierInLogEntry(): void
    {
        // Create a fresh mock so we can override the identifier without clashing with setUp().
        $provider = $this->createMock(TranslationProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');
        $provider->method('translate')->willReturn('Привіт');

        $subject = new TranslationService($provider, $this->logService);

        $this->logService->expects(self::once())
            ->method('log')
            ->with('pages', 1, 'title', 'de', 'uk', 'openai', 'success');

        $subject->translate('Hallo', 'de', 'uk', 'pages', 1, 'title');
    }

    // ── translate() — error handling ──────────────────────────────────────────

    #[Test]
    public function translateRethrowsProviderException(): void
    {
        $this->provider->method('translate')->willThrowException(new RuntimeException('API error'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error');

        $this->subject->translate('text', 'en', 'de');
    }

    #[Test]
    public function translateLogsFailureEntryWhenProviderThrowsAndRecordTableIsGiven(): void
    {
        $this->provider->method('translate')->willThrowException(new RuntimeException('API error'));

        $this->logService->expects(self::once())
            ->method('log')
            ->with('tt_content', 5, 'header', 'en', 'de', 'deepl', 'failed');

        try {
            $this->subject->translate('text', 'en', 'de', 'tt_content', 5, 'header');
        } catch (RuntimeException) {
            // Expected — we only assert the log call above.
        }
    }

    #[Test]
    public function translateSkipsFailureLoggingWhenProviderThrowsAndRecordTableIsEmpty(): void
    {
        $this->provider->method('translate')->willThrowException(new RuntimeException('API error'));

        $this->logService->expects(self::never())->method('log');

        try {
            $this->subject->translate('text', 'en', 'de');
        } catch (RuntimeException) {
            // Expected.
        }
    }
}
