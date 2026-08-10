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

    private const int DEFAULT_MAX_ATTEMPTS = 8;

    private const int DEFAULT_INITIAL_BACKOFF_MS = 1000;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly bool $useFreeApi = true,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $initialBackoffMs = self::DEFAULT_INITIAL_BACKOFF_MS,
    ) {}

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        $baseUrl = $this->useFreeApi ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $url = $baseUrl . '/v2/translate';
        $payload = json_encode([
            'text' => [$text],
            'source_lang' => strtoupper($sourceLanguage),
            'target_lang' => strtoupper($targetLanguage),
        ], JSON_THROW_ON_ERROR);

        $attempt = 0;
        $backoffMs = max(0, $this->initialBackoffMs);
        $lastBody = '';
        $lastStatus = 0;
        $maxAttempts = max(1, $this->maxAttempts);

        while ($attempt < $maxAttempts) {
            $attempt++;
            $response = $this->requestFactory->request($url, 'POST', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => $payload,
            ]);

            $lastStatus = $response->getStatusCode();
            $lastBody = $response->getBody()->getContents();

            if ($lastStatus === 200) {
                $data = json_decode($lastBody, true, 512, JSON_THROW_ON_ERROR);

                return (string) ($data['translations'][0]['text'] ?? '');
            }

            // Free-tier rate limits return 429 — wait and retry with backoff.
            if ($lastStatus === 429 && $attempt < $maxAttempts) {
                if ($backoffMs > 0) {
                    usleep($backoffMs * 1000);
                }
                $backoffMs = min(max(1, $backoffMs) * 2, 30000);
                continue;
            }

            break;
        }

        throw new RuntimeException(
            sprintf('DeepL API error (HTTP %d): %s', $lastStatus, $lastBody),
            self::ERROR_CODE,
        );
    }
}
