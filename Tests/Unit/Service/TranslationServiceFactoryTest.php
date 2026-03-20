<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Service;

use Maispace\MaiTranslate\Service\TranslationServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Maispace\MaiTranslate\Service\DeeplTranslationService;
use Maispace\MaiTranslate\Service\OpenAiTranslationService;

#[CoversClass(TranslationServiceFactory::class)]
final class TranslationServiceFactoryTest extends TestCase
{
    private DeeplTranslationService&MockObject $deeplService;
    private OpenAiTranslationService&MockObject $openAiService;
    private TranslationServiceFactory $factory;

    protected function setUp(): void
    {
        $this->deeplService = $this->createMock(DeeplTranslationService::class);
        $this->deeplService->method('getName')->willReturn('deepl');

        $this->openAiService = $this->createMock(OpenAiTranslationService::class);
        $this->openAiService->method('getName')->willReturn('openai');

        $this->factory = new TranslationServiceFactory($this->deeplService, $this->openAiService);
    }

    #[Test]
    public function getReturnsDeeplService(): void
    {
        self::assertSame($this->deeplService, $this->factory->get('deepl'));
    }

    #[Test]
    public function getReturnsOpenAiService(): void
    {
        self::assertSame($this->openAiService, $this->factory->get('openai'));
    }

    #[Test]
    public function getThrowsForUnknownProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->factory->get('unknown');
    }

    #[Test]
    public function getProviderNamesReturnsAllProviders(): void
    {
        self::assertSame(['deepl', 'openai'], $this->factory->getProviderNames());
    }

    #[Test]
    public function getAvailableProviderNamesReturnsOnlyAvailableProviders(): void
    {
        $this->deeplService->method('isAvailable')->willReturn(true);
        $this->openAiService->method('isAvailable')->willReturn(false);

        self::assertSame(['deepl'], $this->factory->getAvailableProviderNames());
    }

    #[Test]
    public function getAvailableProviderNamesReturnsEmptyArrayWhenNoneAvailable(): void
    {
        $this->deeplService->method('isAvailable')->willReturn(false);
        $this->openAiService->method('isAvailable')->willReturn(false);

        self::assertSame([], $this->factory->getAvailableProviderNames());
    }
}
