<?php

declare(strict_types=1);

namespace Maispace\Translate\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Translation service using the OpenAI Chat Completions API.
 *
 * Uses a system prompt that instructs the model to behave as a professional
 * translator and to preserve any HTML markup in the source text.
 */
final class OpenAiTranslationService implements TranslationServiceInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(
        ExtensionConfiguration $extensionConfiguration,
        private readonly RequestFactory $requestFactory,
    ) {
        $config = $extensionConfiguration->get('translate');
        $this->apiKey = (string)($config['openAiApiKey'] ?? '');
        $this->model = (string)($config['openAiModel'] ?? 'gpt-4o-mini');
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'auto'): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('OpenAI API key is not configured.', 1_700_000_011);
        }

        $systemPrompt = sprintf(
            'You are a professional translator. Translate the provided text to %s. '
            . 'Preserve all HTML tags, attributes, and structure exactly as they appear in the source. '
            . 'Return only the translated text without any explanations or additional content.',
            $targetLanguage
        );

        if ($sourceLanguage !== 'auto' && $sourceLanguage !== '') {
            $systemPrompt .= sprintf(' The source language is %s.', $sourceLanguage);
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0,
        ];

        $response = $this->requestFactory->request(
            'https://api.openai.com/v1/chat/completions',
            'POST',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(
                'OpenAI API error: HTTP ' . $response->getStatusCode() . ' – ' . $response->getBody()->getContents(),
                1_700_000_012
            );
        }

        $data = json_decode($response->getBody()->getContents(), true);
        return (string)($data['choices'][0]['message']['content'] ?? '');
    }
}
