<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Command;

use Doctrine\DBAL\ParameterType;
use Maispace\MaiTranslate\Hydration\FalOverlaySyncService;
use Maispace\MaiTranslate\Hydration\OverlayFieldSyncService;
use Maispace\MaiTranslate\Hydration\OverlaySlugService;
use Maispace\MaiTranslate\Hydration\OverlayStubService;
use Maispace\MaiTranslate\Hydration\TranslationFieldMap;
use Maispace\MaiTranslate\Service\TranslationLengthGuard;
use Maispace\MaiTranslate\Service\TranslationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CLI: `vendor/bin/typo3 mai_translate:hydrate-overlays`
 *
 * Full post-translation pipeline for `sys_language_uid > 0` overlays:
 *   1. Ensure overlay stubs exist (blank text fields, copy_fields from DE)
 *   2. Translate empty translatable fields via DeepL / OpenAI
 *   3. Sync copy_fields (recurrence, dates, typolinks, …) from DE source
 *   4. Sync FAL `sys_file_reference` overlays
 *   5. Regenerate page/news/job slugs via SlugHelper
 *
 * Field map: `scripts/hydration/schema/hydration-v1.json` (`translatable_fields`).
 */
#[AsCommand(
    name: 'mai_translate:hydrate-overlays',
    description: 'Ensure, translate, sync and finalize language overlay rows via mai_translate.',
)]
final class HydrateTranslationsCommand extends Command
{
    private const SOURCE_LANGUAGE_UID = 0;

    public function __construct(
        private readonly TranslationService $translationService,
        private readonly ConnectionPool $connectionPool,
        private readonly OverlayStubService $overlayStubService,
        private readonly OverlayFieldSyncService $overlayFieldSyncService,
        private readonly FalOverlaySyncService $falOverlaySyncService,
        private readonly OverlaySlugService $overlaySlugService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'field-map',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to hydration-v1.json (defaults to scripts/hydration/schema/hydration-v1.json).',
            )
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Restrict run to a single table (e.g. tt_content, tx_maifaq_faq).',
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Restrict run to a single target sys_language_uid (1=en, 2=uk, 3=ar).',
            )
            ->addOption(
                'source-language',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the source ISO code (defaults to "de").',
                'de',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of overlay rows to translate across the whole run.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Skip provider calls and database writes — only report what would happen.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Re-translate fields even when the overlay already holds a non-empty value.',
            )
            ->addOption(
                'skip-ensure',
                null,
                InputOption::VALUE_NONE,
                'Skip creating missing overlay stubs before translation.',
            )
            ->addOption(
                'skip-finalize',
                null,
                InputOption::VALUE_NONE,
                'Skip copy-field sync, FAL overlays and slug regeneration after translation.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceLanguage = (string) ($input->getOption('source-language') ?? 'de');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $skipEnsure = (bool) $input->getOption('skip-ensure');
        $skipFinalize = (bool) $input->getOption('skip-finalize');
        $limit = $input->getOption('limit');
        $limit = $limit === null ? null : max(0, (int) $limit);

        try {
            $fieldMapPath = $this->resolveFieldMapPath((string) ($input->getOption('field-map') ?? ''));
            $fieldMap = TranslationFieldMap::fromFile($fieldMapPath);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $tableFilter = $input->getOption('table');
        $tables = $tableFilter !== null
            ? [(string) $tableFilter]
            : $fieldMap->tableNames();

        $languageFilter = $input->getOption('language');
        $targetLanguages = $languageFilter !== null
            ? [(int) $languageFilter]
            : $fieldMap->targetLanguageUids();

        $io->title('mai_translate :: hydrate-overlays');
        $io->writeln(sprintf('Field map: %s', $fieldMapPath));
        $io->writeln(sprintf('Tables:    %s', implode(', ', $tables)));
        $io->writeln(sprintf('Languages: %s', implode(', ', array_map('strval', $targetLanguages))));
        if ($dryRun) {
            $io->note('Dry-run mode — no provider calls, no database writes.');
        }

        if (!$skipEnsure) {
            $created = $this->overlayStubService->ensureOverlays($fieldMap, $tables, $targetLanguages, $dryRun);
            $io->writeln(sprintf('Ensure stubs: %d overlay(s) %s.', $created, $dryRun ? 'would be created' : 'created'));
        }

        $processed = 0;
        $translated = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true)) {
                $io->warning(sprintf('Table %s is not in the translatable_fields registry — skipping.', $table));
                continue;
            }
            if (!$this->tableExists($table)) {
                $io->warning(sprintf('Table %s does not exist — skipping.', $table));
                continue;
            }

            $columns = $this->tableColumns($table);
            $fields = array_values(array_filter(
                $fieldMap->fieldsFor($table),
                static fn(string $f): bool => isset($columns[$f]),
            ));
            $copyFields = array_values(array_filter(
                $fieldMap->copyFieldsFor($table),
                static fn(string $f): bool => isset($columns[$f]),
            ));
            $parentField = $fieldMap->parentFieldFor($table);
            if (!isset($columns[$parentField])) {
                $io->warning(sprintf('Table %s missing parent field %s — skipping.', $table, $parentField));
                continue;
            }

            foreach ($targetLanguages as $languageUid) {
                $isoCode = $fieldMap->isoCodeFor($languageUid);
                $overlays = $this->findOverlayRows(
                    $table,
                    $languageUid,
                    $parentField,
                    $fields,
                    $copyFields,
                    $force,
                    $limit !== null ? $limit - $processed : null,
                );

                foreach ($overlays as $overlay) {
                    $processed++;
                    $sourceUid = (int) ($overlay[$parentField] ?? 0);
                    if ($sourceUid <= 0) {
                        $skipped++;
                        continue;
                    }
                    $sourceRow = $this->findSourceRow($table, $sourceUid, $fields, $copyFields);
                    if ($sourceRow === null) {
                        $skipped++;
                        continue;
                    }

                    $updates = [];
                    foreach ($fields as $field) {
                        if (!$force && isset($overlay[$field]) && $overlay[$field] !== '' && $overlay[$field] !== '0') {
                            continue;
                        }
                        $sourceValue = (string) ($sourceRow[$field] ?? '');
                        if ($sourceValue === '') {
                            continue;
                        }

                        if ($dryRun) {
                            $updates[$field] = $sourceValue;
                            continue;
                        }

                        $updates[$field] = $this->translationService->translate(
                            text: $sourceValue,
                            sourceLanguage: $sourceLanguage,
                            targetLanguage: $isoCode,
                            recordTable: $table,
                            recordUid: (int) $overlay['uid'],
                            field: $field,
                            maxLength: TranslationLengthGuard::NO_LIMIT,
                        );
                    }

                    foreach ($copyFields as $field) {
                        if (!array_key_exists($field, $sourceRow)) {
                            continue;
                        }
                        $updates[$field] = $sourceRow[$field];
                    }

                    if ($updates === []) {
                        $skipped++;
                        continue;
                    }

                    if (!$dryRun) {
                        $this->connectionPool
                            ->getConnectionForTable($table)
                            ->update($table, $updates, ['uid' => (int) $overlay['uid']]);
                    }
                    $translated++;

                    if ($limit !== null && $processed >= $limit) {
                        break 3;
                    }
                }
            }
        }

        $io->writeln(sprintf(
            'Translate: processed %d overlay rows — %d translated, %d skipped.',
            $processed,
            $translated,
            $skipped,
        ));

        if (!$skipFinalize) {
            $synced = $this->overlayFieldSyncService->syncCopyFields($fieldMap, $tables, $targetLanguages, $dryRun);
            $fal = $this->falOverlaySyncService->syncFalOverlays($fieldMap, $tables, $targetLanguages, $dryRun);
            $slugs = $this->overlaySlugService->regenerateSlugs($fieldMap, $tables, $targetLanguages, $dryRun);
            $io->writeln(sprintf(
                'Finalize: copy_fields=%d, fal=%d, slugs=%d%s.',
                $synced,
                $fal,
                $slugs,
                $dryRun ? ' (dry-run)' : '',
            ));
        }

        $io->success('hydrate-overlays finished.');

        return Command::SUCCESS;
    }

    private function resolveFieldMapPath(string $override): string
    {
        if ($override !== '') {
            return $override;
        }

        return Environment::getProjectPath() . '/scripts/hydration/schema/hydration-v1.json';
    }

    /**
     * @param list<string> $fields
     * @param list<string> $copyFields
     *
     * @return list<array<string, mixed>>
     */
    private function findOverlayRows(
        string $table,
        int $languageUid,
        string $parentField,
        array $fields,
        array $copyFields,
        bool $force,
        ?int $limit,
    ): array {
        $connection = $this->connectionPool->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $query = $connection
            ->select(...array_values(array_unique(['uid', 'sys_language_uid', $parentField, ...$fields, ...$copyFields])))
            ->from($table)
            ->where(
                $connection->expr()->eq('sys_language_uid', $connection->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $connection->expr()->gt($parentField, $connection->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('uid', 'ASC');

        if (!$force) {
            $blankPredicates = [];
            foreach ($fields as $field) {
                $blankPredicates[] = $connection->expr()->or(
                    $connection->expr()->isNull($field),
                    $connection->expr()->eq($field, $connection->createNamedParameter('')),
                );
            }
            if ($blankPredicates !== []) {
                $query->andWhere($connection->expr()->or(...$blankPredicates));
            }
        }

        if ($limit !== null && $limit > 0) {
            $query->setMaxResults($limit);
        }

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<string> $fields
     * @param list<string> $copyFields
     *
     * @return array<string, mixed>|null
     */
    private function findSourceRow(string $table, int $sourceUid, array $fields, array $copyFields): ?array
    {
        $connection = $this->connectionPool->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $row = $connection
            ->select(...array_values(array_unique(['uid', ...$fields, ...$copyFields])))
            ->from($table)
            ->where(
                $connection->expr()->eq('uid', $connection->createNamedParameter($sourceUid, ParameterType::INTEGER)),
                $connection->expr()->eq('sys_language_uid', $connection->createNamedParameter(self::SOURCE_LANGUAGE_UID, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
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
