<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Functional\Command;

use Maispace\MaiTranslate\Provider\TranslationProviderInterface;
use Maispace\MaiTranslate\Service\TranslationLogServiceInterface;
use Maispace\MaiTranslate\Service\TranslationService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the mai_translate:hydrate-overlays CLI command.
 *
 * Tests verify the complete DB roundtrip:
 * - Seeding fixtures with default-language rows and blank overlay rows
 * - Injecting a stubbed TranslationProviderInterface
 * - Running the command and asserting DB updates
 * - Verifying --dry-run leaves DB untouched
 * - Verifying --force re-translates non-empty fields
 * - Verifying --limit short-circuits processing
 */
final class HydrateTranslationsCommandTest extends FunctionalTestCase
{
    /**
     * Extensions required by mai_translate for functional tests.
     */
    protected array $testExtensionsToLoad = [
        'maispace/mai-translate',
    ];

    private CommandTester $commandTester;
    private TranslationProviderInterface&\PHPUnit\Framework\MockObject\MockObject $providerStub;
    private TranslationLogServiceInterface&\PHPUnit\Framework\MockObject\MockObject $logServiceStub;

    protected function setUp(): void
    {
        parent::setUp();

        // Create stubbed provider that returns deterministic translations
        $this->providerStub = $this->createMock(TranslationProviderInterface::class);
        $this->providerStub->method('getIdentifier')->willReturn('stub');
        $this->providerStub->method('translate')->willReturnCallback(
            static fn(string $text, string $source, string $target): string =>
                sprintf('[%s→%s] %s', strtoupper($source), strtoupper($target), $text),
        );

        // Create stubbed log service (no-op for these tests)
        $this->logServiceStub = $this->createMock(TranslationLogServiceInterface::class);

        // Register the stubbed TranslationService in the container
        $translationService = new TranslationService(
            $this->providerStub,
            $this->logServiceStub,
            new \Maispace\MaiTranslate\Service\TranslationLengthGuard(),
        );
        GeneralUtility::setSingletonInstance(TranslationService::class, $translationService);

        $pool = $this->getConnectionPool();
        $command = new \Maispace\MaiTranslate\Command\HydrateTranslationsCommand(
            $translationService,
            $pool,
            new \Maispace\MaiTranslate\Hydration\OverlayStubService($pool),
            new \Maispace\MaiTranslate\Hydration\OverlayFieldSyncService($pool),
            new \Maispace\MaiTranslate\Hydration\FalOverlaySyncService($pool),
            new \Maispace\MaiTranslate\Hydration\OverlaySlugService($pool),
        );
        $this->commandTester = new CommandTester($command);

        // Create the tx_maifaq_faq table manually for testing
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');
        $connection->executeStatement('
            CREATE TABLE tx_maifaq_faq (
                uid INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER DEFAULT 0 NOT NULL,
                sys_language_uid INTEGER DEFAULT 0 NOT NULL,
                question TEXT DEFAULT "" NOT NULL,
                answer TEXT DEFAULT "" NOT NULL,
                l10n_parent INTEGER DEFAULT 0 NOT NULL,
                deleted INTEGER DEFAULT 0 NOT NULL,
                hidden INTEGER DEFAULT 0 NOT NULL
            )
        ');
    }

    /**
     * Create a temporary hydration-v1.json field map file for testing.
     */
    private function createTemporaryFieldMap(array $data): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'hydration_test_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary field map file');
        }
        $json = json_encode(['translatable_fields' => $data], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($tempFile, $json);
        return $tempFile;
    }

    /**
     * Seed the database with a default-language FAQ row and blank overlay rows.
     *
     * @return array{source_uid: int, en_overlay_uid: int, uk_overlay_uid: int, ar_overlay_uid: int}
     */
    private function seedFaqFixtures(): array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');

        // Insert source row (sys_language_uid = 0, German)
        $connection->insert(
            'tx_maifaq_faq',
            [
                'pid' => 1,
                'sys_language_uid' => 0,
                'question' => 'Was ist das?',
                'answer' => 'Das ist eine Test-Antwort.',
                'l10n_parent' => 0,
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $sourceUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        // Insert English overlay (sys_language_uid = 1, blank fields)
        $connection->insert(
            'tx_maifaq_faq',
            [
                'pid' => 1,
                'sys_language_uid' => 1,
                'question' => '',
                'answer' => '',
                'l10n_parent' => $sourceUid,
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $enOverlayUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        // Insert Ukrainian overlay (sys_language_uid = 2, blank fields)
        $connection->insert(
            'tx_maifaq_faq',
            [
                'pid' => 1,
                'sys_language_uid' => 2,
                'question' => '',
                'answer' => '',
                'l10n_parent' => $sourceUid,
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $ukOverlayUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        // Insert Arabic overlay (sys_language_uid = 3, blank fields)
        $connection->insert(
            'tx_maifaq_faq',
            [
                'pid' => 1,
                'sys_language_uid' => 3,
                'question' => '',
                'answer' => '',
                'l10n_parent' => $sourceUid,
                'deleted' => 0,
                'hidden' => 0,
            ],
        );
        $arOverlayUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        return [
            'source_uid' => (int) $sourceUid,
            'en_overlay_uid' => (int) $enOverlayUid,
            'uk_overlay_uid' => (int) $ukOverlayUid,
            'ar_overlay_uid' => (int) $arOverlayUid,
        ];
    }

    #[Test]
    public function commandUpdatesBlankOverlayFields(): void
    {
        $fixtures = $this->seedFaqFixtures();
        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'tx_maifaq_faq' => ['1' => 100, '2' => 200, '3' => 300],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'tx_maifaq_faq',
                ],
            ],
        ]);

        try {
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            // Assert command succeeded
            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

            // Assert overlay rows were updated
            $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');

            $enRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['en_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('[DE→EN] Was ist das?', $enRow['question']);
            self::assertSame('[DE→EN] Das ist eine Test-Antwort.', $enRow['answer']);

            $ukRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['uk_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('[DE→UK] Was ist das?', $ukRow['question']);
            self::assertSame('[DE→UK] Das ist eine Test-Antwort.', $ukRow['answer']);

            $arRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['ar_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('[DE→AR] Was ist das?', $arRow['question']);
            self::assertSame('[DE→AR] Das ist eine Test-Antwort.', $arRow['answer']);
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function dryRunLeavesDatabaseUntouched(): void
    {
        $fixtures = $this->seedFaqFixtures();
        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'tx_maifaq_faq' => ['1' => 100, '2' => 200, '3' => 300],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'tx_maifaq_faq',
                ],
            ],
        ]);

        try {
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--dry-run' => true,
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            // Assert command succeeded
            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

            // Assert overlay rows remain blank
            $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');

            $enRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['en_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('', $enRow['question']);
            self::assertSame('', $enRow['answer']);
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function forceReTranslatesNonEmptyFields(): void
    {
        $fixtures = $this->seedFaqFixtures();

        // Pre-fill the English overlay with existing translations
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');
        $connection->update(
            'tx_maifaq_faq',
            [
                'question' => 'Existing translation',
                'answer' => 'Existing answer',
            ],
            ['uid' => $fixtures['en_overlay_uid']],
        );

        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'tx_maifaq_faq' => ['1' => 100, '2' => 200, '3' => 300],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'tx_maifaq_faq',
                ],
            ],
        ]);

        try {
            // Without --force, the pre-filled overlay should be skipped
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--language' => '1',
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            $enRowBefore = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['en_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('Existing translation', $enRowBefore['question']);
            self::assertSame('Existing answer', $enRowBefore['answer']);

            // With --force, the pre-filled overlay should be re-translated
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--language' => '1',
                '--force' => true,
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            $enRowAfter = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['en_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('[DE→EN] Was ist das?', $enRowAfter['question']);
            self::assertSame('[DE→EN] Das ist eine Test-Antwort.', $enRowAfter['answer']);
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function limitShortCircuitsProcessing(): void
    {
        $fixtures = $this->seedFaqFixtures();
        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'tx_maifaq_faq' => ['1' => 100, '2' => 200, '3' => 300],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'tx_maifaq_faq',
                ],
            ],
        ]);

        try {
            // Limit to 1 overlay — only English should be processed
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--limit' => '1',
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

            $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');

            // English overlay should be updated
            $enRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['en_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('[DE→EN] Was ist das?', $enRow['question']);
            self::assertSame('[DE→EN] Das ist eine Test-Antwort.', $enRow['answer']);

            // Ukrainian and Arabic overlays should remain blank
            $ukRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['uk_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('', $ukRow['question']);
            self::assertSame('', $ukRow['answer']);

            $arRow = $connection->select(['uid', 'question', 'answer'], 'tx_maifaq_faq', ['uid' => $fixtures['ar_overlay_uid']])
                ->fetchAssociative();
            self::assertSame('', $arRow['question']);
            self::assertSame('', $arRow['answer']);
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function commandSkipsUnknownTable(): void
    {
        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'tx_maifaq_faq' => ['1' => 100, '2' => 200, '3' => 300],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'tx_maifaq_faq',
                ],
            ],
        ]);

        try {
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_unknown_table',
                '--skip-ensure' => true,
                '--skip-finalize' => true,
            ]);

            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
            self::assertStringContainsString('tx_unknown_table is not in the translatable_fields registry', $this->commandTester->getDisplay());
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function finalizeSyncsCopyFieldsOntoOverlays(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_maifaq_faq');
        $connection->executeStatement('ALTER TABLE tx_maifaq_faq ADD COLUMN sorting INTEGER DEFAULT 0 NOT NULL');

        $connection->insert('tx_maifaq_faq', [
            'pid' => 1,
            'sys_language_uid' => 0,
            'question' => 'Q?',
            'answer' => 'A.',
            'l10n_parent' => 0,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => 42,
        ]);
        $sourceUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        $connection->insert('tx_maifaq_faq', [
            'pid' => 1,
            'sys_language_uid' => 1,
            'question' => 'Already translated',
            'answer' => 'Already translated',
            'l10n_parent' => $sourceUid,
            'deleted' => 0,
            'hidden' => 0,
            'sorting' => 0,
        ]);
        $overlayUid = (int) $connection->lastInsertId('tx_maifaq_faq');

        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en'],
            'overlay_uid_offsets' => [
                'domain_records' => ['1' => 1000],
            ],
            'tables' => [
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'copy_fields' => ['sorting'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'domain_records',
                ],
            ],
        ]);

        try {
            // Text already filled → translate loop skips; finalize must still sync copy_fields.
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tx_maifaq_faq',
                '--language' => '1',
                '--skip-ensure' => true,
            ]);

            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

            $row = $connection->select(['question', 'sorting'], 'tx_maifaq_faq', ['uid' => $overlayUid])
                ->fetchAssociative();
            self::assertSame('Already translated', $row['question']);
            self::assertSame(42, (int) $row['sorting']);
        } finally {
            @unlink($fieldMapPath);
        }
    }

    #[Test]
    public function finalizeSyncsTypolinkTargetsAndTranslatesLinkLabels(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->executeStatement('DROP TABLE IF EXISTS tt_content');
        $connection->executeStatement('
            CREATE TABLE tt_content (
                uid INTEGER PRIMARY KEY AUTOINCREMENT,
                pid INTEGER DEFAULT 0 NOT NULL,
                sys_language_uid INTEGER DEFAULT 0 NOT NULL,
                header TEXT DEFAULT "" NOT NULL,
                bodytext TEXT DEFAULT "" NOT NULL,
                header_link TEXT DEFAULT "" NOT NULL,
                tx_maitheme_link TEXT DEFAULT "" NOT NULL,
                tx_maitheme_link_text TEXT DEFAULT "" NOT NULL,
                tx_maitheme_link_secondary TEXT DEFAULT "" NOT NULL,
                tx_maitheme_link_secondary_text TEXT DEFAULT "" NOT NULL,
                l18n_parent INTEGER DEFAULT 0 NOT NULL,
                deleted INTEGER DEFAULT 0 NOT NULL,
                hidden INTEGER DEFAULT 0 NOT NULL,
                starttime INTEGER DEFAULT 0 NOT NULL,
                endtime INTEGER DEFAULT 0 NOT NULL,
                fe_group TEXT DEFAULT "" NOT NULL,
                tstamp INTEGER DEFAULT 0 NOT NULL
            )
        ');

        $connection->insert('tt_content', [
            'pid' => 1,
            'sys_language_uid' => 0,
            'header' => 'Bunter Tisch',
            'bodytext' => '<p>DE body</p>',
            'header_link' => '',
            'tx_maitheme_link' => 't3://page?uid=8',
            'tx_maitheme_link_text' => 'Mehr erfahren',
            'tx_maitheme_link_secondary' => 't3://page?uid=13',
            'tx_maitheme_link_secondary_text' => 'Jetzt mithelfen',
            'l18n_parent' => 0,
            'deleted' => 0,
            'hidden' => 0,
            'tstamp' => 1,
        ]);
        $sourceUid = (int) $connection->lastInsertId('tt_content');

        // Incomplete overlay: translated texts present, typolinks + CTA labels missing
        // (regression from stubs created before link fields were in the field map).
        $connection->insert('tt_content', [
            'pid' => 1,
            'sys_language_uid' => 1,
            'header' => 'Colorful Table',
            'bodytext' => '<p>EN body</p>',
            'header_link' => '',
            'tx_maitheme_link' => '',
            'tx_maitheme_link_text' => '',
            'tx_maitheme_link_secondary' => '',
            'tx_maitheme_link_secondary_text' => '',
            'l18n_parent' => $sourceUid,
            'deleted' => 0,
            'hidden' => 0,
            'tstamp' => 1,
        ]);
        $overlayUid = (int) $connection->lastInsertId('tt_content');

        $fieldMapPath = $this->createTemporaryFieldMap([
            'language_uid_map' => ['1' => 'en'],
            'overlay_uid_offsets' => [
                'tt_content' => ['1' => 10000],
            ],
            'tables' => [
                'tt_content' => [
                    'fields' => [
                        'header',
                        'bodytext',
                        'tx_maitheme_link_text',
                        'tx_maitheme_link_secondary_text',
                    ],
                    'copy_fields' => [
                        'header_link',
                        'tx_maitheme_link',
                        'tx_maitheme_link_secondary',
                    ],
                    'parent_field' => 'l18n_parent',
                    'uid_offset_key' => 'tt_content',
                ],
            ],
        ]);

        try {
            $this->commandTester->execute([
                '--field-map' => $fieldMapPath,
                '--table' => 'tt_content',
                '--language' => '1',
                '--skip-ensure' => true,
            ]);

            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());

            $row = $connection->select(
                [
                    'header',
                    'tx_maitheme_link',
                    'tx_maitheme_link_text',
                    'tx_maitheme_link_secondary',
                    'tx_maitheme_link_secondary_text',
                ],
                'tt_content',
                ['uid' => $overlayUid],
            )->fetchAssociative();

            self::assertSame('Colorful Table', $row['header']);
            self::assertSame('t3://page?uid=8', $row['tx_maitheme_link']);
            self::assertSame('t3://page?uid=13', $row['tx_maitheme_link_secondary']);
            self::assertSame('[DE→EN] Mehr erfahren', $row['tx_maitheme_link_text']);
            self::assertSame('[DE→EN] Jetzt mithelfen', $row['tx_maitheme_link_secondary_text']);
        } finally {
            @unlink($fieldMapPath);
        }
    }
}
