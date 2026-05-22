<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Provider;

interface TranslationProviderInterface
{
    /**
     * Returns a stable machine-readable identifier for this provider (e.g. 'deepl', 'openai').
     */
    public function getIdentifier(): string;

    /**
     * Translate the given text from the source language to the target language.
     *
     * @param string $text           The plain or HTML text to translate.
     * @param string $sourceLanguage Source language ISO code (e.g. 'en', 'de').
     * @param string $targetLanguage Target language ISO code (e.g. 'de', 'uk').
     *
     * @return string The translated text.
     *
     * @throws \RuntimeException When the provider returns an error or cannot fulfil the request.
     */
    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string;
}
