<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

/**
 * Stable contract for generating text embeddings used in the search indexing pipeline.
 *
 * Implementations wrap an embedding model API (e.g. OpenAI text-embedding-3-small)
 * and provide a uniform interface for the mai_search index loop to produce vector
 * representations of indexed content chunks.
 *
 * Each embedding service identifies itself via getIdentifier() so that callers can
 * log or differentiate models at runtime without coupling to a concrete class.
 *
 * Token-count estimation (getTokenCount()) is a character-based approximation and
 * is intended for pre-flight chunk-size validation only — it is NOT a substitute
 * for a true tokeniser like tiktoken in production RAG pipelines.
 */
interface EmbeddingServiceInterface
{
    /**
     * Generate an embedding vector for a single text string.
     *
     * @param string $text The input text to embed.
     *
     * @return list<float> The embedding vector.
     *
     * @throws \RuntimeException When the embedding API returns an error.
     */
    public function embedText(string $text): array;

    /**
     * Generate embedding vectors for multiple texts in a single batch request.
     *
     * @param list<string> $texts Array of input texts to embed.
     *
     * @return list<list<float>> Array of embedding vectors, one per input text,
     *                           in the same order as $texts.
     *
     * @throws \RuntimeException When the embedding API returns an error.
     */
    public function embedTexts(array $texts): array;

    /**
     * Estimate the token count for the given text using a character-based
     * approximation (~4 characters per token on average for English text).
     *
     * This is a rough estimate for checking input length limits before
     * sending to the API. Minimum returned value is 1.
     *
     * @param string $text The input text.
     *
     * @return int Estimated token count, at least 1.
     */
    public function getTokenCount(string $text): int;

    /**
     * Return the maximum number of input tokens supported by this embedding model.
     *
     * @return int Maximum input token count (e.g. 8191 for text-embedding-3-small).
     */
    public function getMaxInputTokens(): int;

    /**
     * Return the dimensionality of the embedding vectors produced by this model.
     *
     * @return int Vector dimension (e.g. 1536 for text-embedding-3-small).
     */
    public function getVectorDimension(): int;

    /**
     * Return a stable machine-readable identifier for this embedding service
     * (e.g. 'openai/text-embedding-3-small').
     *
     * @return string Stable identifier.
     */
    public function getIdentifier(): string;
}
