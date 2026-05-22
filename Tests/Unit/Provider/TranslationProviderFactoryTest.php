<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Provider;

use Maispace\MaiTranslate\Provider\DeepLTranslationProvider;
use Maispace\MaiTranslate\Provider\OpenAiTranslationProvider;
use Maispace\MaiTranslate\Provider\TranslationProviderFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

final class TranslationProviderFactoryTest extends TestCase
{
    private RequestFactory&MockObject $requestFactory;
    private ExtensionConfiguration&MockObject $extensionConfiguration;

    protected function setUp(): void
    {
        $this->requestFactory = $this->createMock(RequestFactory::class);
        $this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
    }

    // ── create() — provider selection ────────────────────────────────────────

    #[Test]
    public function createReturnsDeepLProviderByDefault(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'deepl',
            'deepLApiKey' => 'test-key',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);
        $provider = $factory->create();

        self::assertInstanceOf(DeepLTranslationProvider::class, $provider);
    }

    #[Test]
    public function createReturnsDeepLProviderWhenProviderIsDeepL(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'deepl',
            'deepLApiKey' => 'key',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);

        self::assertInstanceOf(DeepLTranslationProvider::class, $factory->create());
    }

    #[Test]
    public function createReturnsOpenAiProviderWhenProviderIsOpenai(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'openai',
            'openAiApiKey' => 'sk-test',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);

        self::assertInstanceOf(OpenAiTranslationProvider::class, $factory->create());
    }

    #[Test]
    public function createReturnsDeepLProviderForUnknownProviderIdentifier(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'unknown_provider',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);

        self::assertInstanceOf(DeepLTranslationProvider::class, $factory->create());
    }

    #[Test]
    public function createReturnsDeepLProviderWhenConfigIsEmpty(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);

        self::assertInstanceOf(DeepLTranslationProvider::class, $factory->create());
    }

    #[Test]
    public function createReturnsDeepLProviderWhenExtensionConfigThrows(): void
    {
        $this->extensionConfiguration
            ->method('get')
            ->willThrowException(new \RuntimeException('Extension not found'));

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);

        self::assertInstanceOf(DeepLTranslationProvider::class, $factory->create());
    }

    // ── create() — DeepL free API detection ──────────────────────────────────

    #[Test]
    public function createDeepLProviderWithFreeApiKeyHasDeepLIdentifier(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'deepl',
            'deepLApiKey' => 'my-free-key:fx',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);
        $provider = $factory->create();

        self::assertInstanceOf(DeepLTranslationProvider::class, $provider);
        self::assertSame('deepl', $provider->getIdentifier());
    }

    #[Test]
    public function createOpenAiProviderHasOpenAiIdentifier(): void
    {
        $this->extensionConfiguration->method('get')->willReturn([
            'provider' => 'openai',
            'openAiApiKey' => 'sk-test',
            'openAiModel' => 'gpt-4o',
        ]);

        $factory = new TranslationProviderFactory($this->requestFactory, $this->extensionConfiguration);
        $provider = $factory->create();

        self::assertInstanceOf(OpenAiTranslationProvider::class, $provider);
        self::assertSame('openai', $provider->getIdentifier());
    }
}
