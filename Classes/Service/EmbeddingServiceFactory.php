<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Reads the mai_translate extension settings at runtime and returns the configured
 * embedding service instance.
 *
 * Reuses the 'openAiApiKey' setting from the translation provider configuration
 * so that a single OpenAI API key covers both translation and embedding use cases.
 *
 * The factory must be registered in Services.yaml as the factory for
 * EmbeddingServiceInterface — the concrete service class requires the API key
 * as a scalar constructor argument that cannot be auto-wired.
 */
final class EmbeddingServiceFactory
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): EmbeddingServiceInterface
    {
        $config = $this->getConfig();

        return new OpenAiEmbeddingService(
            $this->requestFactory,
            (string) ($config['openAiApiKey'] ?? ''),
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
