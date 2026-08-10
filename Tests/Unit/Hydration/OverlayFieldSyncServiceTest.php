<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Hydration;

use Maispace\MaiTranslate\Hydration\OverlayFieldSyncService;
use Maispace\MaiTranslate\Hydration\TranslationFieldMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Smoke-level constructor / type checks — DB behaviour is covered by functional tests.
 */
final class OverlayFieldSyncServiceTest extends TestCase
{
    #[Test]
    public function syncCopyFieldsReturnsZeroForUnknownTables(): void
    {
        $pool = $this->createMock(ConnectionPool::class);
        $service = new OverlayFieldSyncService($pool);
        $map = TranslationFieldMap::fromArray([
            'language_uid_map' => ['1' => 'en'],
            'overlay_uid_offsets' => ['domain_records' => ['1' => 1000]],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'domain_records',
                    'copy_fields' => ['sorting'],
                ],
            ],
        ]);

        // Table not requested → early continue, no ConnectionPool calls needed for empty tables list mismatch
        self::assertSame(0, $service->syncCopyFields($map, ['tx_unknown'], [1], true));
    }
}
