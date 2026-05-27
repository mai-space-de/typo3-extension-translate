<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

final class TranslationLogService implements TranslationLogServiceInterface
{
    private const string TABLE_NAME = 'tx_maitranslate_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Write a translation log entry.
     *
     * @param string $recordTable  Name of the table whose record was translated (e.g. 'tt_content')
     * @param int    $recordUid    UID of the translated record
     * @param string $field        Name of the translated field (e.g. 'bodytext')
     * @param string $sourceLanguage  Source language ISO code (e.g. 'en')
     * @param string $targetLanguage  Target language ISO code (e.g. 'de')
     * @param string $provider     Translation provider identifier ('deepl' or 'openai')
     * @param string $status       Translation result ('success', 'truncated', or 'failed')
     */
    public function log(
        string $recordTable,
        int $recordUid,
        string $field,
        string $sourceLanguage,
        string $targetLanguage,
        string $provider,
        string $status,
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
        $timestamp = time();

        $connection->insert(self::TABLE_NAME, [
            'pid' => 0,
            'tstamp' => $timestamp,
            'crdate' => $timestamp,
            'record_table' => $recordTable,
            'record_uid' => $recordUid,
            'field' => $field,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'provider' => $provider,
            'status' => $status,
        ]);
    }
}
