<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

interface TranslationServiceInterface
{
    /**
     * Translate a text string into the target language.
     *
     * @param string $text           The text to translate
     * @param string $targetLanguage The target language code (e.g. 'DE', 'EN', 'FR')
     * @param string $sourceLanguage The source language code, or 'auto' for auto-detection
     * @return string The translated text
     */
    public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'auto'): string;

    /**
     * Returns the provider identifier (e.g. 'deepl', 'openai').
     */
    public function getName(): string;

    /**
     * Returns true when this service is properly configured and can be used.
     */
    public function isAvailable(): bool;
}
