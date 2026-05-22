<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Provider;

use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Translation provider that calls the DeepL REST API.
 *
 * Free-tier keys end with ':fx' and must use api-free.deepl.com.
 * Paid keys use api.deepl.com.
 */
final class DeepLTranslationProvider implements TranslationProviderInterface
{
    private const int ERROR_CODE = 1748100001;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly bool $useFreeApi = true,
    ) {}

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        $baseUrl = $this->useFreeApi ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $url = $baseUrl . '/v2/translate';

        $response = $this->requestFactory->request($url, 'POST', [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'text' => [$text],
                'source_lang' => strtoupper($sourceLanguage),
                'target_lang' => strtoupper($targetLanguage),
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            throw new RuntimeException(
                sprintf('DeepL API error (HTTP %d): %s', $statusCode, $body),
                self::ERROR_CODE,
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return (string) ($data['translations'][0]['text'] ?? '');
    }
}
