<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regenerates overlay slugs via TYPO3 SlugHelper from translated titles.
 */
final class OverlaySlugService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<string> $tables
     * @param list<int>    $languageUids
     *
     * @return int Number of slug fields updated
     */
    public function regenerateSlugs(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $languageUids,
        bool $dryRun = false,
    ): int {
        $updated = 0;

        // Pages first (depth-sorted) so prefixParentPageSlug sees localized parents.
        if (in_array('pages', $tables, true) && $fieldMap->shouldRegenerateSlug('pages')) {
            $updated += $this->regeneratePageSlugs($languageUids, $dryRun);
        }

        foreach ($tables as $table) {
            if ($table === 'pages' || !in_array($table, $fieldMap->tableNames(), true)) {
                continue;
            }
            if (!$fieldMap->shouldRegenerateSlug($table)) {
                continue;
            }
            if (!$this->tableExists($table) || !isset($this->tableColumns($table)['slug'])) {
                continue;
            }
            $updated += $this->regenerateRecordSlugs($table, $languageUids, $dryRun);
        }

        return $updated;
    }

    /**
     * @param list<int> $languageUids
     */
    private function regeneratePageSlugs(array $languageUids, bool $dryRun): int
    {
        if (!$this->tableExists('pages')) {
            return 0;
        }

        $helper = GeneralUtility::makeInstance(
            SlugHelper::class,
            'pages',
            'slug',
            $this->pagesSlugConfig(),
        );
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('*')
            ->from('pages')
            ->where(
                $qb->expr()->in(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
                ),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $depthCache = [];
        usort(
            $rows,
            function (array $a, array $b) use (&$depthCache): int {
                $da = $this->pageDepth((int) $a['uid'], $depthCache);
                $db = $this->pageDepth((int) $b['uid'], $depthCache);

                return $da <=> $db ?: ((int) $a['uid'] <=> (int) $b['uid']);
            },
        );

        $updated = 0;
        foreach ($rows as $row) {
            $uid = (int) $row['uid'];
            $pid = (int) $row['pid'];
            $l10nParent = (int) ($row['l10n_parent'] ?? 0);
            $old = (string) ($row['slug'] ?? '');
            $isRoot = $pid === 0 || !empty($row['is_siteroot']) || $l10nParent === 1;

            if ($isRoot) {
                $slug = '/';
            } else {
                try {
                    $proposal = $helper->generate($row, $pid);
                    if ($proposal === '' || $proposal === '/') {
                        $proposal = $this->fetchDefaultSlug($l10nParent);
                    }
                    $state = RecordStateFactory::forName('pages')->fromArray($row, $pid, $uid);
                    $slug = $helper->buildSlugForUniqueInSite($proposal, $state);
                } catch (\Throwable) {
                    $slug = $this->fetchDefaultSlug($l10nParent);
                }
            }

            if ($slug === $old) {
                continue;
            }
            $updated++;
            if (!$dryRun) {
                $connection->update('pages', ['slug' => $slug, 'tstamp' => time()], ['uid' => $uid]);
            }
        }

        return $updated;
    }

    /**
     * @param list<int> $languageUids
     */
    private function regenerateRecordSlugs(string $table, array $languageUids, bool $dryRun): int
    {
        $helper = GeneralUtility::makeInstance(
            SlugHelper::class,
            $table,
            'slug',
            $this->recordSlugConfig($table),
        );
        $connection = $this->connectionPool->getConnectionForTable($table);
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')->from($table)->where(
            $qb->expr()->in(
                'sys_language_uid',
                $qb->createNamedParameter($languageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY),
            ),
        );
        if (isset($this->tableColumns($table)['deleted'])) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $updated = 0;
        foreach ($rows as $row) {
            $uid = (int) $row['uid'];
            $pid = (int) ($row['pid'] ?? 0);
            $old = (string) ($row['slug'] ?? '');
            $proposal = $helper->generate($row, $pid);
            if ($proposal === '' || $proposal === '/') {
                $proposal = 'record-' . $uid;
            }
            $state = RecordStateFactory::forName($table)->fromArray($row, $pid, $uid);
            try {
                $slug = $helper->buildSlugForUniqueInSite($proposal, $state);
            } catch (\Throwable) {
                $slug = $helper->buildSlugForUniqueInPid($proposal, $state);
            }

            if ($slug === $old) {
                continue;
            }
            $updated++;
            if (!$dryRun) {
                $connection->update($table, ['slug' => $slug, 'tstamp' => time()], ['uid' => $uid]);
            }
        }

        return $updated;
    }

    /**
     * @param array<int, int> $cache
     */
    private function pageDepth(int $uid, array &$cache): int
    {
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid', 'pid')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return $cache[$uid] = 0;
        }
        $pid = (int) $row['pid'];
        if ($pid <= 0) {
            return $cache[$uid] = 0;
        }

        return $cache[$uid] = 1 + $this->pageDepth($pid, $cache);
    }

    private function fetchDefaultSlug(int $l10nParent): string
    {
        if ($l10nParent <= 0) {
            return '/';
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $slug = $qb->select('slug')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($l10nParent, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        return is_string($slug) && $slug !== '' ? $slug : '/';
    }

    /**
     * @return array<string, mixed>
     */
    private function pagesSlugConfig(): array
    {
        return $GLOBALS['TCA']['pages']['columns']['slug']['config'] ?? [
            'type' => 'slug',
            'generatorOptions' => [
                'fields' => ['title'],
                'fieldSeparator' => '/',
                'prefixParentPageSlug' => true,
            ],
            'fallbackCharacter' => '-',
            'eval' => 'uniqueInSite',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSlugConfig(string $table): array
    {
        $config = $GLOBALS['TCA'][$table]['columns']['slug']['config'] ?? null;
        if (is_array($config)) {
            return $config;
        }

        return [
            'type' => 'slug',
            'eval' => 'uniqueInSite',
            'fallbackCharacter' => '-',
            'generatorOptions' => [
                'fields' => ['title'],
                'fieldSeparator' => '-',
                'replacements' => ['/' => ''],
            ],
        ];
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
