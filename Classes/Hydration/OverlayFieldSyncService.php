<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Copies non-translatable `copy_fields` from default-language source rows onto overlays.
 *
 * Idempotent: always overwrites copy_fields so technical data (recurrence, dates,
 * typolink targets such as `header_link` / `tx_maitheme_link*`, …) stays in sync
 * after overlay stubs were created incompletely.
 */
final class OverlayFieldSyncService
{
    private const SOURCE_LANGUAGE_UID = 0;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $tables
     * @param list<int>    $languageUids
     *
     * @return int Number of overlay rows updated
     */
    public function syncCopyFields(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $languageUids,
        bool $dryRun = false,
    ): int {
        $updated = 0;

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true)) {
                continue;
            }
            if (!$this->tableExists($table)) {
                continue;
            }

            $columns = $this->tableColumns($table);
            $parentField = $fieldMap->parentFieldFor($table);
            $copyFields = array_values(array_filter(
                $fieldMap->copyFieldsFor($table),
                static fn(string $f): bool => isset($columns[$f]),
            ));
            if ($copyFields === [] || !isset($columns[$parentField])) {
                continue;
            }

            foreach ($languageUids as $languageUid) {
                $overlays = $this->findOverlays($table, $parentField, $languageUid, $copyFields, isset($columns['deleted']));
                $connection = $this->connectionPool->getConnectionForTable($table);

                foreach ($overlays as $overlay) {
                    $sourceUid = (int) ($overlay[$parentField] ?? 0);
                    if ($sourceUid <= 0) {
                        continue;
                    }
                    $source = $this->findSource($table, $sourceUid, $copyFields);
                    if ($source === null) {
                        continue;
                    }

                    $updates = [];
                    foreach ($copyFields as $field) {
                        $srcVal = $source[$field] ?? null;
                        $ovVal = $overlay[$field] ?? null;
                        if ($srcVal !== $ovVal) {
                            $updates[$field] = $srcVal;
                        }
                    }
                    if ($updates === []) {
                        continue;
                    }
                    if (isset($columns['tstamp'])) {
                        $updates['tstamp'] = time();
                    }

                    $updated++;
                    if (!$dryRun) {
                        $connection->update($table, $updates, ['uid' => (int) $overlay['uid']]);
                    }
                }
            }
        }

        return $updated;
    }

    /**
     * @param list<string> $copyFields
     *
     * @return list<array<string, mixed>>
     */
    private function findOverlays(
        string $table,
        string $parentField,
        int $languageUid,
        array $copyFields,
        bool $hasDeleted,
    ): array {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $qb->select(...array_unique(['uid', $parentField, ...$copyFields]))
            ->from($table)
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $qb->expr()->gt($parentField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
            );
        if ($hasDeleted) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<string> $copyFields
     *
     * @return array<string, mixed>|null
     */
    private function findSource(string $table, int $sourceUid, array $copyFields): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select(...array_unique(['uid', ...$copyFields]))
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($sourceUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(self::SOURCE_LANGUAGE_UID, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function tableExists(string $table): bool
    {
        return $this->connectionPool
            ->getConnectionForTable($table)
            ->createSchemaManager()
            ->tablesExist([$table]);
    }

    /**
     * @return array<string, true>
     */
    private function tableColumns(string $table): array
    {
        $set = [];
        foreach (
            $this->connectionPool->getConnectionForTable($table)->createSchemaManager()->listTableColumns($table) as $col
        ) {
            $set[$col->getName()] = true;
        }

        return $set;
    }
}
