<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Result;
use Maispace\MaiTranslate\Domain\Model\TranslationLog;
use Maispace\MaiTranslate\Domain\Repository\TranslationLogRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class TranslationLogRepositoryTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;
    private TranslationLogRepository $subject;

    protected function setUp(): void
    {
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new TranslationLogRepository($this->connectionPool);
    }

    private function makeRow(int $uid = 1, string $status = 'success'): array
    {
        return [
            'uid' => $uid,
            'record_table' => 'tt_content',
            'record_uid' => 42,
            'field' => 'bodytext',
            'source_language' => 'en',
            'target_language' => 'de',
            'provider' => 'deepl',
            'status' => $status,
        ];
    }

    private function buildQueryBuilder(array $rows): QueryBuilder&MockObject
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $exprBuilder = $this->createMock(ExpressionBuilder::class);
        $exprBuilder->method('eq')->willReturnArgument(0);
        $queryBuilder->method('expr')->willReturn($exprBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnArgument(0);

        return $queryBuilder;
    }

    // ── findRecent() ─────────────────────────────────────────────────────────

    #[Test]
    public function findRecentReturnsEmptyArrayWhenNoRows(): void
    {
        $queryBuilder = $this->buildQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $result = $this->subject->findRecent(10);

        self::assertSame([], $result);
    }

    #[Test]
    public function findRecentReturnsHydratedTranslationLogObjects(): void
    {
        $queryBuilder = $this->buildQueryBuilder([$this->makeRow(5, 'success')]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $logs = $this->subject->findRecent(5);

        self::assertCount(1, $logs);
        self::assertInstanceOf(TranslationLog::class, $logs[0]);
        self::assertSame(5, $logs[0]->getUid());
        self::assertSame('tt_content', $logs[0]->getRecordTable());
        self::assertSame(42, $logs[0]->getRecordUid());
        self::assertSame('bodytext', $logs[0]->getField());
        self::assertSame('en', $logs[0]->getSourceLanguage());
        self::assertSame('de', $logs[0]->getTargetLanguage());
        self::assertSame('deepl', $logs[0]->getProvider());
        self::assertTrue($logs[0]->isSuccess());
    }

    #[Test]
    public function findRecentReturnsMultipleRows(): void
    {
        $queryBuilder = $this->buildQueryBuilder([
            $this->makeRow(1, 'success'),
            $this->makeRow(2, 'failed'),
        ]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $logs = $this->subject->findRecent(10);

        self::assertCount(2, $logs);
        self::assertSame(1, $logs[0]->getUid());
        self::assertSame(2, $logs[1]->getUid());
        self::assertTrue($logs[0]->isSuccess());
        self::assertFalse($logs[1]->isSuccess());
    }

    // ── findByRecordTable() ───────────────────────────────────────────────────

    #[Test]
    public function findByRecordTableReturnsEmptyArrayWhenNoRows(): void
    {
        $queryBuilder = $this->buildQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $result = $this->subject->findByRecordTable('pages');

        self::assertSame([], $result);
    }

    #[Test]
    public function findByRecordTableReturnsHydratedObjects(): void
    {
        $row = $this->makeRow(3, 'success');
        $row['record_table'] = 'pages';
        $row['field'] = 'title';

        $queryBuilder = $this->buildQueryBuilder([$row]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $logs = $this->subject->findByRecordTable('pages');

        self::assertCount(1, $logs);
        self::assertSame('pages', $logs[0]->getRecordTable());
        self::assertSame('title', $logs[0]->getField());
    }
}
