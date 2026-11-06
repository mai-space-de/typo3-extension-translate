<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Creates missing `sys_file_reference` language overlays for translated parent records.
 */
final class FalOverlaySyncService
{
    private const SOURCE_LANGUAGE_UID = 0;

    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $tables
     * @param list<int>    $languageUids
     *
     * @return int Number of FAL overlay refs created
     */
    public function syncFalOverlays(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $languageUids,
        bool $dryRun = false,
    ): int {
        if (!$this->tableExists('sys_file_reference')) {
            return 0;
        }

        $created = 0;
        $now = time();
        $falConnection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $existing = $this->existingFalOverlays();

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true)) {
                continue;
            }
            $falSpecs = $fieldMap->falSpecsFor($table);
            if ($falSpecs === [] || !$this->tableExists($table)) {
                continue;
            }

            $parentField = $fieldMap->parentFieldFor($table);

            foreach ($falSpecs as $falSpec) {
                $fieldname = $falSpec['fieldname'];
                $countField = $falSpec['count_field'];

                $deRefs = $this->findDefaultFalRefs($table, $fieldname);
                foreach ($deRefs as $ref) {
                    foreach ($languageUids as $languageUid) {
                        if (isset($existing[(int) $ref['uid']][$languageUid])) {
                            continue;
                        }

                        $overlayUid = $this->findOverlayUid($table, $parentField, (int) $ref['uid_foreign'], $languageUid);
                        if ($overlayUid === null) {
                            continue;
                        }

                        $row = [
                            'pid' => $table === 'pages' ? $overlayUid : (int) ($ref['pid'] ?? 0),
                            'tstamp' => $now,
                            'crdate' => $now,
                            'deleted' => 0,
                            'hidden' => (int) ($ref['hidden'] ?? 0),
                            'sys_language_uid' => $languageUid,
                            'l10n_parent' => (int) $ref['uid'],
                            'uid_local' => (int) $ref['uid_local'],
                            'uid_foreign' => $overlayUid,
                            'tablenames' => $table,
                            'fieldname' => $fieldname,
                            'sorting_foreign' => (int) ($ref['sorting_foreign'] ?? 0),
                            'title' => '',
                            'alternative' => '',
                            'description' => '',
                            'crop' => $ref['crop'] ?? null,
                            'link' => (string) ($ref['link'] ?? ''),
                            'autoplay' => (int) ($ref['autoplay'] ?? 0),
                        ];

                        $created++;
                        if (!$dryRun) {
                            $falConnection->insert('sys_file_reference', $row);
                            $this->bumpCountField($table, $overlayUid, $fieldname, $countField);
                        }
                        $existing[(int) $ref['uid']][$languageUid] = true;
                    }
                }
            }
        }

        return $created;
    }

    /**
     * File-reference texts that still need a translation.
     *
     * Missing overlays are included only when `$countMissingOverlays` is true
     * (dry-run). A real run creates those rows first, with empty text fields.
     *
     * @param list<string> $tables
     * @param list<int>    $languageUids
     *
     * @return list<array{uid: int, field: string, text: string, languageUid: int, reason: string}>
     */
    public function collectTextJobs(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $languageUids,
        bool $countMissingOverlays,
    ): array {
        if (!$this->tableExists('sys_file_reference') || $languageUids === []) {
            return [];
        }

        $falColumns = $this->tableColumns('sys_file_reference');
        $diffColumn = $this->diffColumn($falColumns);
        $jobs = [];

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true) || !$this->tableExists($table)) {
                continue;
            }
            $textFields = array_values(array_filter(
                $fieldMap->falTextFieldsFor($table),
                static fn(string $field): bool => isset($falColumns[$field]),
            ));
            if ($textFields === [] || $fieldMap->falSpecsFor($table) === []) {
                continue;
            }

            $parentField = $fieldMap->parentFieldFor($table);
            foreach ($fieldMap->falSpecsFor($table) as $falSpec) {
                foreach ($this->findDefaultFalRefs($table, $falSpec['fieldname']) as $ref) {
                    $sourceUid = (int) $ref['uid_foreign'];
                    foreach ($languageUids as $languageUid) {
                        $overlay = $this->findFalOverlayRow((int) $ref['uid'], $languageUid, $textFields, $diffColumn);
                        if ($overlay === null) {
                            if (!$countMissingOverlays) {
                                continue;
                            }
                            if (
                                $this->findOverlayUid($table, $parentField, $sourceUid, $languageUid) === null
                                && !$this->defaultLanguageRowExists($table, $sourceUid)
                            ) {
                                continue;
                            }
                            foreach ($textFields as $field) {
                                $text = trim((string) ($ref[$field] ?? ''));
                                if ($text === '') {
                                    continue;
                                }
                                $jobs[] = [
                                    'uid' => 0,
                                    'field' => $field,
                                    'text' => (string) $ref[$field],
                                    'languageUid' => $languageUid,
                                    'reason' => 'missing-overlay',
                                ];
                            }
                            continue;
                        }

                        $snapshot = OverlayFieldDiff::decode($diffColumn === null ? null : ($overlay[$diffColumn] ?? null));
                        foreach ($textFields as $field) {
                            $text = (string) ($ref[$field] ?? '');
                            $reason = OverlayFieldDiff::reason($text, $overlay[$field] ?? '', $snapshot, $field);
                            if ($reason === null) {
                                continue;
                            }
                            $jobs[] = [
                                'uid' => (int) $overlay['uid'],
                                'field' => $field,
                                'text' => $text,
                                'languageUid' => $languageUid,
                                'reason' => $reason,
                            ];
                        }
                    }
                }
            }
        }

        return $jobs;
    }

    /**
     * @param array<string, true> $columns
     */
    private function diffColumn(array $columns): ?string
    {
        if (isset($columns['l18n_diffsource'])) {
            return 'l18n_diffsource';
        }
        if (isset($columns['l10n_diffsource'])) {
            return 'l10n_diffsource';
        }

        return null;
    }

    /**
     * @param list<string> $textFields
     *
     * @return array<string, mixed>|null
     */
    private function findFalOverlayRow(int $sourceRefUid, int $languageUid, array $textFields, ?string $diffColumn): ?array
    {
        $select = ['uid', ...$textFields];
        if ($diffColumn !== null) {
            $select[] = $diffColumn;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select(...array_values(array_unique($select)))
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($sourceRefUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function defaultLanguageRowExists(string $table, int $uid): bool
    {
        if ($uid <= 0 || !$this->tableExists($table)) {
            return false;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $qb->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(self::SOURCE_LANGUAGE_UID, ParameterType::INTEGER)),
            );
        if (isset($this->tableColumns($table)['deleted'])) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }

        return (int) $qb->executeQuery()->fetchOne() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findDefaultFalRefs(string $table, string $fieldname): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();

        return $qb->select('*')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(self::SOURCE_LANGUAGE_UID, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldname)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<int, true>>
     */
    private function existingFalOverlays(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('l10n_parent', 'sys_language_uid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->gt('l10n_parent', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['l10n_parent']][(int) $row['sys_language_uid']] = true;
        }

        return $map;
    }

    private function findOverlayUid(string $table, string $parentField, int $sourceUid, int $languageUid): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq($parentField, $qb->createNamedParameter($sourceUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? null : (int) $uid;
    }

    private function bumpCountField(string $table, int $overlayUid, string $fieldname, string $countField): void
    {
        $columns = $this->tableColumns($table);
        if (!isset($columns[$countField])) {
            return;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $count = (int) $qb->count('uid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($overlayUid, ParameterType::INTEGER)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldname)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();

        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [$countField => $count, 'tstamp' => time()],
            ['uid' => $overlayUid],
        );
    }

    private function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }

        return $this->tableExistsCache[$table] = $this->connectionPool
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
