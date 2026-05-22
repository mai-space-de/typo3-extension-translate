<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Provider;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Reads the mai_translate extension settings at runtime and returns the configured
 * translation provider instance.
 *
 * Supported provider identifiers (extension setting "provider"):
 *   - 'deepl'  (default) — calls the DeepL REST API
 *   - 'openai'           — calls the OpenAI Chat Completions API
 *
 * DeepL free-tier keys end with ':fx'; the factory detects this automatically
 * and routes to the correct DeepL base URL.
 */
final class TranslationProviderFactory
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): TranslationProviderInterface
    {
        $config = $this->getConfig();
        $provider = (string) ($config['provider'] ?? 'deepl');

        if ($provider === 'openai') {
            return new OpenAiTranslationProvider(
                $this->requestFactory,
                (string) ($config['openAiApiKey'] ?? ''),
                (string) ($config['openAiModel'] ?? 'gpt-4o-mini'),
            );
        }

        // Default: DeepL. Free API keys end with ':fx'.
        $apiKey = (string) ($config['deepLApiKey'] ?? '');

        return new DeepLTranslationProvider(
            $this->requestFactory,
            $apiKey,
            str_ends_with($apiKey, ':fx'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        try {
            $config = $this->extensionConfiguration->get('mai_translate');
        } catch (\Exception) {
            $config = [];
        }

        return is_array($config) ? $config : [];
    }
}
