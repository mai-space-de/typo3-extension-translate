<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

interface TranslationLogServiceInterface
{
    /**
     * Write a translation log entry.
     *
     * @param string $recordTable    Name of the table whose record was translated (e.g. 'tt_content')
     * @param int    $recordUid      UID of the translated record
     * @param string $field          Name of the translated field (e.g. 'bodytext')
     * @param string $sourceLanguage Source language ISO code (e.g. 'en')
     * @param string $targetLanguage Target language ISO code (e.g. 'de')
     * @param string $provider       Translation provider identifier ('deepl' or 'openai')
     * @param string $status         Translation result ('success', 'truncated', or 'failed')
     */
    public function log(
        string $recordTable,
        int $recordUid,
        string $field,
        string $sourceLanguage,
        string $targetLanguage,
        string $provider,
        string $status,
    ): void;
}
