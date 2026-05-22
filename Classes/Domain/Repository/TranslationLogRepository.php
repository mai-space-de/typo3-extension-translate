<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Domain\Repository;

use Maispace\MaiTranslate\Domain\Model\TranslationLog;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class TranslationLogRepository
{
    private const string TABLE_NAME = 'tx_maitranslate_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return TranslationLog[]
     */
    public function findRecent(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row) => self::hydrate($row), $rows);
    }

    /**
     * @return TranslationLog[]
     */
    public function findByRecordTable(string $tableName, int $limit = 100): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'record_table',
                    $queryBuilder->createNamedParameter($tableName),
                ),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row) => self::hydrate($row), $rows);
    }

    private static function hydrate(array $row): TranslationLog
    {
        return new TranslationLog(
            uid: (int) $row['uid'],
            recordTable: (string) $row['record_table'],
            recordUid: (int) $row['record_uid'],
            field: (string) $row['field'],
            sourceLanguage: (string) $row['source_language'],
            targetLanguage: (string) $row['target_language'],
            provider: (string) $row['provider'],
            status: (string) $row['status'],
        );
    }
}
