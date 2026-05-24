<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Embedding service using OpenAI's text-embedding-3-small model.
 *
 * Produces 1536-dimensional float vectors. Maximum input length is 8191 tokens.
 * Token-count estimation uses a ~4-characters-per-token approximation and is
 * intended for pre-flight validation only.
 *
 * @see https://platform.openai.com/docs/guides/embeddings
 */
final class OpenAiEmbeddingService implements EmbeddingServiceInterface
{
    private const string API_URL = 'https://api.openai.com/v1/embeddings';
    private const string MODEL = 'text-embedding-3-small';
    private const int ERROR_CODE = 1748100003;
    private const float TOKENS_PER_CHAR = 0.25;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly string $apiKey,
        private readonly int $vectorDimension = 1536,
        private readonly int $maxInputTokens = 8191,
    ) {}

    public function getIdentifier(): string
    {
        return 'openai/text-embedding-3-small';
    }

    public function embedText(string $text): array
    {
        $results = $this->callApi([$text]);
        return $results[0] ?? [];
    }

    public function embedTexts(array $texts): array
    {
        return $this->callApi($texts);
    }

    public function getTokenCount(string $text): int
    {
        $count = (int) ceil(mb_strlen($text) * self::TOKENS_PER_CHAR);
        return max(1, $count);
    }

    public function getMaxInputTokens(): int
    {
        return $this->maxInputTokens;
    }

    public function getVectorDimension(): int
    {
        return $this->vectorDimension;
    }

    /**
     * @param list<string> $inputs
     *
     * @return list<list<float>>
     */
    private function callApi(array $inputs): array
    {
        $response = $this->requestFactory->request(self::API_URL, 'POST', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => self::MODEL,
                'input' => $inputs,
                'dimensions' => $this->vectorDimension,
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            throw new RuntimeException(
                sprintf('OpenAI Embedding API error (HTTP %d): %s', $statusCode, $body),
                self::ERROR_CODE,
            );
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $embeddings = $data['data'] ?? [];

        // Sort by index to maintain the original input order
        usort($embeddings, static fn(array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            static fn(array $entry): array => $entry['embedding'] ?? [],
            $embeddings,
        );
    }
}
