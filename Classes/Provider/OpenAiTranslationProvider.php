<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Provider;

use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Translation provider that calls the OpenAI Chat Completions API.
 *
 * The model defaults to gpt-4o-mini, which is accurate and cost-effective.
 * Any chat-capable model (gpt-4o, gpt-4-turbo, gpt-3.5-turbo) is accepted.
 */
final class OpenAiTranslationProvider implements TranslationProviderInterface
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';
    private const int ERROR_CODE = 1748100002;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
    ) {}

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        $response = $this->requestFactory->request(self::API_URL, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional translator. '
                            . 'Translate the given text accurately, preserving formatting, HTML tags, and meaning. '
                            . 'Return only the translated text without any explanation or additional commentary.',
                    ],
                    [
                        'role' => 'user',
                        'content' => sprintf(
                            'Translate the following text from %s to %s:' . "\n\n" . '%s',
                            strtoupper($sourceLanguage),
                            strtoupper($targetLanguage),
                            $text,
                        ),
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            throw new RuntimeException(
                sprintf('OpenAI API error (HTTP %d): %s', $statusCode, $body),
                self::ERROR_CODE,
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return (string) ($data['choices'][0]['message']['content'] ?? '');
    }
}
