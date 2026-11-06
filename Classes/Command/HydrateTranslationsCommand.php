<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Command;

use Doctrine\DBAL\ParameterType;
use Maispace\MaiTranslate\Hydration\FalOverlaySyncService;
use Maispace\MaiTranslate\Hydration\OverlayFieldDiff;
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
 *   2. Translate empty, still-German and out-of-sync fields via DeepL / OpenAI
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
        $characters = [
            OverlayFieldDiff::REASON_EMPTY => 0,
            OverlayFieldDiff::REASON_STILL_GERMAN => 0,
            OverlayFieldDiff::REASON_SOURCE_CHANGED => 0,
            'missing-overlay' => 0,
            'fal' => 0,
            'force' => 0,
        ];
        $charactersByTable = [];

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
            $diffColumn = $this->diffColumn($columns);
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
                    $diffColumn,
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

                    $snapshot = OverlayFieldDiff::decode($diffColumn === null ? null : ($overlay[$diffColumn] ?? null));
                    $updates = [];
                    foreach ($fields as $field) {
                        $sourceValue = (string) ($sourceRow[$field] ?? '');
                        if ($force) {
                            if ($sourceValue === '') {
                                continue;
                            }
                            $reason = 'force';
                        } else {
                            $reason = OverlayFieldDiff::reason($sourceValue, $overlay[$field] ?? '', $snapshot, $field);
                            if ($reason === null) {
                                continue;
                            }
                        }

                        $fieldCharacters = OverlayFieldDiff::characters($sourceValue);
                        $characters[$reason] += $fieldCharacters;
                        $charactersByTable[$table] = ($charactersByTable[$table] ?? 0) + $fieldCharacters;

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

                    if ($updates !== []) {
                        foreach ($copyFields as $field) {
                            if (!array_key_exists($field, $sourceRow)) {
                                continue;
                            }
                            $updates[$field] = $sourceRow[$field];
                        }
                    }

                    if ($updates === []) {
                        $skipped++;
                    } else {
                        if (!$dryRun) {
                            $this->connectionPool
                                ->getConnectionForTable($table)
                                ->update($table, $updates, ['uid' => (int) $overlay['uid']]);
                        }
                        $translated++;
                    }

                    if (!$dryRun) {
                        $this->writeSnapshot($table, (int) $overlay['uid'], $diffColumn, $snapshot, $sourceRow, $fields);
                    }

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

        if ($dryRun && !$skipEnsure) {
            $missing = $this->overlayStubService->estimateMissingOverlayCharacters($fieldMap, $tables, $targetLanguages);
            $characters['missing-overlay'] += $missing['characters'];
            foreach ($missing['byTable'] as $table => $tableCharacters) {
                $charactersByTable[$table] = ($charactersByTable[$table] ?? 0) + $tableCharacters;
            }
            $io->writeln(sprintf(
                'Missing overlays: %d record(s), %d character(s).',
                $missing['records'],
                $missing['characters'],
            ));
        }

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

        $this->translateFalTexts(
            $fieldMap,
            $tables,
            $targetLanguages,
            $sourceLanguage,
            $dryRun,
            $characters,
            $charactersByTable,
            $io,
        );
        $this->reportCharacters($characters, $charactersByTable, $io);

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
        ?string $diffColumn,
        ?int $limit,
    ): array {
        $select = ['uid', 'sys_language_uid', $parentField, ...$fields, ...$copyFields];
        if ($diffColumn !== null) {
            $select[] = $diffColumn;
        }

        $connection = $this->connectionPool->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $query = $connection
            ->select(...array_values(array_unique($select)))
            ->from($table)
            ->where(
                $connection->expr()->eq('sys_language_uid', $connection->createNamedParameter($languageUid, ParameterType::INTEGER)),
                $connection->expr()->gt($parentField, $connection->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('uid', 'ASC');

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
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $sourceRow
     * @param list<string> $fields
     */
    private function writeSnapshot(
        string $table,
        int $overlayUid,
        ?string $diffColumn,
        array $snapshot,
        array $sourceRow,
        array $fields,
    ): void {
        if ($diffColumn === null || $overlayUid <= 0) {
            return;
        }

        $sourceFields = [];
        foreach ($fields as $field) {
            $sourceFields[$field] = (string) ($sourceRow[$field] ?? '');
        }
        $merged = OverlayFieldDiff::withSourceValues($snapshot, $sourceFields);
        if (!$merged['changed']) {
            return;
        }

        try {
            $encoded = json_encode($merged['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        $this->connectionPool->getConnectionForTable($table)->update(
            $table,
            [$diffColumn => $encoded],
            ['uid' => $overlayUid],
        );
    }

    /**
     * @param array<string, int> $characters
     * @param array<string, int> $charactersByTable
     * @param list<string> $tables
     * @param list<int> $targetLanguages
     */
    private function translateFalTexts(
        TranslationFieldMap $fieldMap,
        array $tables,
        array $targetLanguages,
        string $sourceLanguage,
        bool $dryRun,
        array &$characters,
        array &$charactersByTable,
        SymfonyStyle $io,
    ): void {
        $jobs = $this->falOverlaySyncService->collectTextJobs($fieldMap, $tables, $targetLanguages, $dryRun);
        $snapshots = [];

        foreach ($jobs as $job) {
            $fieldCharacters = OverlayFieldDiff::characters($job['text']);
            $characters['fal'] += $fieldCharacters;
            $charactersByTable['sys_file_reference'] = ($charactersByTable['sys_file_reference'] ?? 0) + $fieldCharacters;
            if ($dryRun || $job['uid'] <= 0) {
                continue;
            }

            $translatedText = $this->translationService->translate(
                text: $job['text'],
                sourceLanguage: $sourceLanguage,
                targetLanguage: $fieldMap->isoCodeFor($job['languageUid']),
                recordTable: 'sys_file_reference',
                recordUid: $job['uid'],
                field: $job['field'],
                maxLength: TranslationLengthGuard::NO_LIMIT,
            );
            $this->connectionPool->getConnectionForTable('sys_file_reference')->update(
                'sys_file_reference',
                [$job['field'] => $translatedText],
                ['uid' => $job['uid']],
            );
            $snapshots[$job['uid']][$job['field']] = $job['text'];
        }

        if (!$dryRun) {
            $this->writeFalSnapshots($snapshots);
        }

        $io->writeln(sprintf('File references: %d text field(s), %d character(s).', count($jobs), $characters['fal']));
    }

    /**
     * @param array<int, array<string, string>> $snapshots
     */
    private function writeFalSnapshots(array $snapshots): void
    {
        if ($snapshots === [] || !$this->tableExists('sys_file_reference')) {
            return;
        }

        $diffColumn = $this->diffColumn($this->tableColumns('sys_file_reference'));
        if ($diffColumn === null) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_file_reference');
        foreach ($snapshots as $uid => $fields) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
            $qb->getRestrictions()->removeAll();
            $raw = $qb->select($diffColumn)
                ->from('sys_file_reference')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
            $merged = OverlayFieldDiff::withSourceValues(OverlayFieldDiff::decode($raw === false ? null : $raw), $fields);
            if (!$merged['changed']) {
                continue;
            }
            try {
                $encoded = json_encode($merged['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $connection->update('sys_file_reference', [$diffColumn => $encoded], ['uid' => $uid]);
        }
    }

    /**
     * @param array<string, int> $characters
     * @param array<string, int> $charactersByTable
     */
    private function reportCharacters(array $characters, array $charactersByTable, SymfonyStyle $io): void
    {
        $total = array_sum($characters);
        $io->writeln(sprintf('Characters to translate: %d', $total));
        $io->writeln(sprintf('  empty fields: %d', $characters[OverlayFieldDiff::REASON_EMPTY]));
        $io->writeln(sprintf('  still German: %d', $characters[OverlayFieldDiff::REASON_STILL_GERMAN]));
        $io->writeln(sprintf('  source changed: %d', $characters[OverlayFieldDiff::REASON_SOURCE_CHANGED]));
        $io->writeln(sprintf('  missing overlays: %d', $characters['missing-overlay']));
        $io->writeln(sprintf('  file references: %d', $characters['fal']));
        if ($characters['force'] > 0) {
            $io->writeln(sprintf('  forced: %d', $characters['force']));
        }
        ksort($charactersByTable);
        foreach ($charactersByTable as $table => $tableCharacters) {
            if ($tableCharacters > 0) {
                $io->writeln(sprintf('  %s: %d', $table, $tableCharacters));
            }
        }
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
