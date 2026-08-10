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
                            'pid' => (int) ($ref['pid'] ?? 0),
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
                            'title' => $ref['title'] ?? null,
                            'alternative' => $ref['alternative'] ?? null,
                            'description' => $ref['description'] ?? null,
                            'crop' => $ref['crop'] ?? null,
                            'link' => (string) ($ref['link'] ?? ''),
                            'autoplay' => (int) ($ref['autoplay'] ?? 0),
                        ];

                        $created++;
                        if (!$dryRun) {
                            $falConnection->insert('sys_file_reference', $row);
                            $this->bumpCountField($table, $overlayUid, $countField);
                        }
                        $existing[(int) $ref['uid']][$languageUid] = true;
                    }
                }
            }
        }

        return $created;
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

    private function bumpCountField(string $table, int $overlayUid, string $countField): void
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
