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
        'packages/typo3-extension-translate',
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

        // Get the command from the container with required dependencies
        $command = new \Maispace\MaiTranslate\Command\HydrateTranslationsCommand(
            $translationService,
            $this->getConnectionPool(),
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
            ]);

            self::assertSame(Command::SUCCESS, $this->commandTester->getStatusCode());
            self::assertStringContainsString('tx_unknown_table is not in the translatable_fields registry', $this->commandTester->getDisplay());
        } finally {
            @unlink($fieldMapPath);
        }
    }
}
