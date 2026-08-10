<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Creates missing language overlay stub rows for tables in the field map.
 *
 * Translatable fields are left blank for {@see \Maispace\MaiTranslate\Command\HydrateTranslationsCommand};
 * copy_fields are filled from the default-language source.
 */
final class OverlayStubService
{
    private const SOURCE_LANGUAGE_UID = 0;

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $tables
     * @param list<int>    $languageUids
     *
     * @return int Number of overlay stubs created
     */
    public function ensureOverlays(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $languageUids,
        bool $dryRun = false,
    ): int {
        $created = 0;
        $now = time();

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true)) {
                continue;
            }
            if (!$this->tableExists($table)) {
                continue;
            }

            $columns = $this->tableColumns($table);
            $parentField = $fieldMap->parentFieldFor($table);
            if (!isset($columns[$parentField])) {
                continue;
            }

            $fields = array_values(array_filter(
                $fieldMap->fieldsFor($table),
                static fn(string $f): bool => isset($columns[$f]),
            ));
            $copyFields = array_values(array_filter(
                $fieldMap->copyFieldsFor($table),
                static fn(string $f): bool => isset($columns[$f]),
            ));
            $selectCopy = $copyFields;
            if (isset($columns['slug']) && !in_array('slug', $selectCopy, true)) {
                $selectCopy[] = 'slug';
            }

            $sources = $this->findSourceRows($table, $selectCopy, isset($columns['deleted']));
            $existing = $this->existingOverlayParents($table, $parentField, $languageUids, isset($columns['deleted']));
            $connection = $this->connectionPool->getConnectionForTable($table);

            foreach ($sources as $source) {
                $sourceUid = (int) $source['uid'];
                foreach ($languageUids as $languageUid) {
                    if (isset($existing[$sourceUid][$languageUid])) {
                        continue;
                    }

                    $row = [
                        'sys_language_uid' => $languageUid,
                        $parentField => $sourceUid,
                    ];
                    if (isset($columns['l10n_source'])) {
                        $row['l10n_source'] = $sourceUid;
                    }
                    if ($table === 'tt_content' && isset($columns['l18n_parent'])) {
                        $row['l18n_parent'] = $sourceUid;
                    }
                    if ($table === 'pages' && isset($columns['l10n_parent'])) {
                        $row['l10n_parent'] = $sourceUid;
                    }
                    if (isset($columns['tstamp'])) {
                        $row['tstamp'] = $now;
                    }
                    if (isset($columns['crdate'])) {
                        $row['crdate'] = $now;
                    }
                    if (isset($columns['deleted'])) {
                        $row['deleted'] = 0;
                    }

                    foreach ($copyFields as $field) {
                        if (array_key_exists($field, $source)) {
                            $row[$field] = $source[$field];
                        }
                    }
                    foreach ($fields as $field) {
                        $row[$field] = '';
                    }
                    if ($table === 'pages' && isset($columns['slug']) && isset($source['slug'])) {
                        $row['slug'] = $source['slug'];
                    }

                    $created++;
                    if (!$dryRun) {
                        $connection->insert($table, $row);
                    }
                }
            }
        }

        return $created;
    }

    /**
     * @param list<string> $copyFields
     *
     * @return list<array<string, mixed>>
     */
    private function findSourceRows(string $table, array $copyFields, bool $hasDeleted): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $select = array_values(array_unique(['uid', ...$copyFields]));

        $qb->select(...$select)->from($table)->where(
            $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(self::SOURCE_LANGUAGE_UID, ParameterType::INTEGER)),
        );
        if ($hasDeleted) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }

        return $qb->orderBy('uid', 'ASC')->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<int> $languageUids
     *
     * @return array<int, array<int, true>>
     */
    private function existingOverlayParents(
        string $table,
        string $parentField,
        array $languageUids,
        bool $hasDeleted,
    ): array {
        if ($languageUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $qb->select($parentField, 'sys_language_uid')
            ->from($table)
            ->where(
                $qb->expr()->gt($parentField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->in(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
                ),
            );
        if ($hasDeleted) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }

        $map = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $map[(int) $row[$parentField]][(int) $row['sys_language_uid']] = true;
        }

        return $map;
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
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $set = [];
        foreach (
            $this->connectionPool->getConnectionForTable($table)->createSchemaManager()->listTableColumns($table) as $col
        ) {
            $set[$col->getName()] = true;
        }

        return $this->columnCache[$table] = $set;
    }
}
