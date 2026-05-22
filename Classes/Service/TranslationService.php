<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

use Maispace\MaiTranslate\Provider\TranslationProviderInterface;

/**
 * Orchestrates a single translation request:
 *   1. Delegates the translation to the injected provider.
 *   2. Writes a success or failure entry to the translation log.
 *
 * The concrete provider (DeepL or OpenAI) is resolved by the DI container via
 * TranslationProviderFactory::create() — see Configuration/Services.yaml.
 *
 * Upstream callers (e.g. a backend DataHandler hook or CLI command) should inject
 * this service and call translate() with the record context so that every API call
 * is auditable via the TranslationLog backend list.
 *
 * Logging is skipped when $recordTable is an empty string so the service can also
 * be used for ad-hoc translations (e.g. preview mode) without polluting the log.
 */
final class TranslationService
{
    public function __construct(
        private readonly TranslationProviderInterface $provider,
        private readonly TranslationLogServiceInterface $logService,
    ) {}

    /**
     * Translate a single text string and (optionally) write a log entry.
     *
     * @param string $text           Text to translate (plain or HTML).
     * @param string $sourceLanguage Source language ISO code (e.g. 'en').
     * @param string $targetLanguage Target language ISO code (e.g. 'de').
     * @param string $recordTable    DB table of the translated record (pass '' to skip logging).
     * @param int    $recordUid      UID of the translated record.
     * @param string $field          Field name that was translated (e.g. 'bodytext').
     *
     * @return string Translated text.
     *
     * @throws \RuntimeException When the provider signals an API error.
     */
    public function translate(
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        string $recordTable = '',
        int $recordUid = 0,
        string $field = '',
    ): string {
        $status = 'success';

        try {
            $translated = $this->provider->translate($text, $sourceLanguage, $targetLanguage);
        } catch (\Throwable $e) {
            $status = 'failed';

            if ($recordTable !== '') {
                $this->logService->log(
                    $recordTable,
                    $recordUid,
                    $field,
                    $sourceLanguage,
                    $targetLanguage,
                    $this->provider->getIdentifier(),
                    $status,
                );
            }

            throw $e;
        }

        if ($recordTable !== '') {
            $this->logService->log(
                $recordTable,
                $recordUid,
                $field,
                $sourceLanguage,
                $targetLanguage,
                $this->provider->getIdentifier(),
                $status,
            );
        }

        return $translated;
    }
}
