<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Command;

use Doctrine\DBAL\ParameterType;
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
 * Fills the translatable fields of `sys_language_uid > 0` overlay rows created
 * by hydrate-13 (`scripts/hydration/generate-translation-overlays.py`) using
 * {@see TranslationService}. The list of translatable fields per table is read
 * from the `translatable_fields` registry inside
 * `scripts/hydration/schema/hydration-v1.json`.
 *
 * Workflow per overlay row:
 *   1. Look up the default-language source row via the table's parent field
 *      (`l10n_parent` for tx_* / sys_category, `l18n_parent` for tt_content).
 *   2. For every translatable field that is empty on the overlay, translate the
 *      corresponding source value (DeepL / OpenAI provider, decided by extension
 *      configuration) and UPDATE the overlay row.
 *
 * The command is idempotent: it skips overlays whose translatable fields are
 * already non-empty. Use `--force` to re-translate them.
 */
#[AsCommand(
    name: 'mai_translate:hydrate-overlays',
    description: 'Fill translation overlay rows produced by hydrate-13 using mai_translate providers.',
)]
final class HydrateTranslationsCommand extends Command
{
    private const SOURCE_LANGUAGE_UID = 0;

    public function __construct(
        private readonly TranslationService $translationService,
        private readonly ConnectionPool $connectionPool,
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
                'Maximum number of overlay rows to process across the whole run.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Skip provider calls and database writes — only report what would be translated.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Re-translate fields even when the overlay already holds a non-empty value.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceLanguage = (string) ($input->getOption('source-language') ?? 'de');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
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

        $processed = 0;
        $translated = 0;
        $skipped = 0;

        foreach ($tables as $table) {
            if (!in_array($table, $fieldMap->tableNames(), true)) {
                $io->warning(sprintf('Table %s is not in the translatable_fields registry — skipping.', $table));
                continue;
            }
            $fields = $fieldMap->fieldsFor($table);
            $parentField = $fieldMap->parentFieldFor($table);

            foreach ($targetLanguages as $languageUid) {
                $isoCode = $fieldMap->isoCodeFor($languageUid);
                $overlays = $this->findOverlayRows($table, $languageUid, $parentField, $fields, $force, $limit !== null ? $limit - $processed : null);

                foreach ($overlays as $overlay) {
                    $processed++;
                    $sourceUid = (int) ($overlay[$parentField] ?? 0);
                    if ($sourceUid <= 0) {
                        $skipped++;
                        continue;
                    }
                    $sourceRow = $this->findSourceRow($table, $sourceUid, $fields);
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

        $io->success(sprintf(
            'Processed %d overlay rows: %d translated, %d skipped.',
            $processed,
            $translated,
            $skipped,
        ));

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
     *
     * @return list<array<string, mixed>>
     */
    private function findOverlayRows(
        string $table,
        int $languageUid,
        string $parentField,
        array $fields,
        bool $force,
        ?int $limit,
    ): array {
        $connection = $this->connectionPool->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $query = $connection
            ->select(...array_unique(['uid', 'sys_language_uid', $parentField, ...$fields]))
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
     *
     * @return array<string, mixed>|null
     */
    private function findSourceRow(string $table, int $sourceUid, array $fields): ?array
    {
        $connection = $this->connectionPool->getQueryBuilderForTable($table);
        $connection->getRestrictions()->removeAll();
        $row = $connection
            ->select(...array_unique(['uid', ...$fields]))
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
}
