<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Resolves a child column such as `parent_uid` to the translated foreign row.
 *
 * Inline records store the German content element in `parent_uid`. The overlay
 * must point at the translated content element instead.
 */
final class ForeignPointerResolver
{
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array{field: string, foreign_table: string, foreign_parent_field: string} $pointer
     */
    public function resolve(array $pointer, int $sourceForeignUid, int $languageUid): int
    {
        if ($sourceForeignUid <= 0 || $languageUid <= 0) {
            return 0;
        }
        if (!$this->tableExists($pointer['foreign_table'])) {
            return 0;
        }

        $columns = $this->tableColumns($pointer['foreign_table']);
        if (!isset($columns[$pointer['foreign_parent_field']])) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($pointer['foreign_table']);
        $qb->getRestrictions()->removeAll();
        $qb->select('uid')
            ->from($pointer['foreign_table'])
            ->where(
                $qb->expr()->eq(
                    $pointer['foreign_parent_field'],
                    $qb->createNamedParameter($sourceForeignUid, ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageUid, ParameterType::INTEGER),
                ),
            )
            ->setMaxResults(1);
        if (isset($columns['deleted'])) {
            $qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER)));
        }

        $uid = $qb->executeQuery()->fetchOne();

        return $uid === false ? 0 : (int) $uid;
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
