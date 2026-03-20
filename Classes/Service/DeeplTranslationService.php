<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Translation service using the DeepL API.
 *
 * Supports both the free (api-free.deepl.com) and the pro (api.deepl.com) tiers.
 * The API key suffix ':fx' indicates a free-tier key.
 */
final class DeeplTranslationService implements TranslationServiceInterface
{
    private string $apiKey;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private readonly RequestFactory $requestFactory,
    ) {
        try {
            $config = $extensionConfiguration->get('translate');
            $this->apiKey = (string)($config['deeplApiKey'] ?? '');
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            $this->apiKey = '';
        }
    }

    public function getName(): string
    {
        return 'deepl';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'auto'): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('DeepL API key is not configured.', 1_700_000_001);
        }

        $apiUrl = str_contains($this->apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        $params = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLanguage),
            'tag_handling' => 'html',
        ];

        if ($sourceLanguage !== 'auto' && $sourceLanguage !== '') {
            $params['source_lang'] = strtoupper($sourceLanguage);
        }

        $response = $this->requestFactory->request(
            $apiUrl,
            'POST',
            [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($params),
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                'DeepL API error: HTTP ' . $response->getStatusCode() . ' – ' . $response->getBody()->getContents(),
                1_700_000_002
            );
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return (string)($data['translations'][0]['text'] ?? '');
    }
}
