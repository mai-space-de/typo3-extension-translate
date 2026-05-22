<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Service;

use Maispace\MaiTranslate\Service\TranslationLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class TranslationLogServiceTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;
    private TranslationLogService $subject;

    protected function setUp(): void
    {
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new TranslationLogService($this->connectionPool);
    }

    // ── log() ────────────────────────────────────────────────────────────────

    #[Test]
    public function logInsertsRowIntoLogTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_maitranslate_log',
                self::callback(static function (array $data): bool {
                    return $data['record_table'] === 'tt_content'
                        && $data['record_uid'] === 7
                        && $data['field'] === 'bodytext'
                        && $data['source_language'] === 'en'
                        && $data['target_language'] === 'de'
                        && $data['provider'] === 'deepl'
                        && $data['status'] === 'success'
                        && $data['pid'] === 0;
                }),
            );

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->subject->log('tt_content', 7, 'bodytext', 'en', 'de', 'deepl', 'success');
    }

    #[Test]
    public function logInsertsRowWithOpenAiProvider(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_maitranslate_log',
                self::callback(static function (array $data): bool {
                    return $data['provider'] === 'openai'
                        && $data['status'] === 'failed';
                }),
            );

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->subject->log('pages', 1, 'title', 'de', 'uk', 'openai', 'failed');
    }

    #[Test]
    public function logInsertsRowWithTimestamps(): void
    {
        $before = time();

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_maitranslate_log',
                self::callback(static function (array $data) use ($before): bool {
                    $after = time();
                    return isset($data['tstamp'], $data['crdate'])
                        && $data['tstamp'] >= $before
                        && $data['tstamp'] <= $after
                        && $data['crdate'] >= $before
                        && $data['crdate'] <= $after;
                }),
            );

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->subject->log('tt_content', 1, 'bodytext', 'en', 'de', 'deepl', 'success');
    }

    #[Test]
    public function logCallsConnectionForCorrectTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert');

        $this->connectionPool->expects(self::once())
            ->method('getConnectionForTable')
            ->with('tx_maitranslate_log')
            ->willReturn($connection);

        $this->subject->log('tt_content', 1, 'bodytext', 'en', 'de', 'deepl', 'success');
    }

    #[Test]
    public function logPassesAllFieldsToInsert(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_maitranslate_log',
                self::callback(static function (array $data): bool {
                    $expectedKeys = ['pid', 'tstamp', 'crdate', 'record_table', 'record_uid',
                        'field', 'source_language', 'target_language', 'provider', 'status'];
                    foreach ($expectedKeys as $key) {
                        if (!array_key_exists($key, $data)) {
                            return false;
                        }
                    }
                    return true;
                }),
            );

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->subject->log('tt_content', 1, 'bodytext', 'en', 'de', 'deepl', 'success');
    }
}
