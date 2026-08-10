<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

use RuntimeException;

/**
 * Strongly-typed view of the `translatable_fields` registry exported by
 * `scripts/hydration/schema/hydration-v1.json` (hydrate-13).
 *
 * Loaded by {@see \Maispace\MaiTranslate\Command\HydrateTranslationsCommand} to
 * decide which overlay rows must be filled by `mai_translate` and which fields
 * on each row are subject to translation, copy-sync, FAL overlays, and slug regen.
 *
 * The schema file lives outside the extension because it is a project-wide
 * hydration contract shared with the Python overlay generator
 * (`scripts/hydration/generate-translation-overlays.py`). The Symfony command
 * therefore accepts the path as an option / constructor argument; the default
 * is resolved relative to the TYPO3 public path.
 */
final class TranslationFieldMap
{
    /**
     * @param array<int, string> $languageUidMap sys_language_uid → ISO code
     * @param array<string, array{
     *     fields: list<string>,
     *     copy_fields: list<string>,
     *     parent_field: string,
     *     uid_offset_key: string,
     *     regenerate_slug: bool,
     *     fal: list<array{fieldname: string, count_field: string}>
     * }> $tables
     * @param array<string, array<int, int>> $overlayUidOffsets per uid_offset_key + language uid → offset
     */
    public function __construct(
        public readonly array $languageUidMap,
        public readonly array $tables,
        public readonly array $overlayUidOffsets,
    ) {}

    /**
     * @throws RuntimeException When the file cannot be read or the registry is malformed.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Hydration schema not found or unreadable: %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Failed to read hydration schema: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                sprintf('Hydration schema is not valid JSON: %s', $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded) || !isset($decoded['translatable_fields']) || !is_array($decoded['translatable_fields'])) {
            throw new RuntimeException(
                'Hydration schema is missing the "translatable_fields" registry. '
                . 'Regenerate the schema via hydrate-13.',
            );
        }

        return self::fromArray($decoded['translatable_fields']);
    }

    /**
     * @param array<string, mixed> $registry The decoded `translatable_fields` block.
     *
     * @throws RuntimeException When required keys are missing or malformed.
     */
    public static function fromArray(array $registry): self
    {
        $languageUidMap = $registry['language_uid_map'] ?? null;
        if (!is_array($languageUidMap) || $languageUidMap === []) {
            throw new RuntimeException('translatable_fields.language_uid_map must be a non-empty object.');
        }
        $normalisedLangMap = [];
        foreach ($languageUidMap as $uid => $code) {
            if (!is_string($code) || $code === '') {
                throw new RuntimeException('translatable_fields.language_uid_map entries must be non-empty strings.');
            }
            $normalisedLangMap[(int) $uid] = $code;
        }

        $offsets = $registry['overlay_uid_offsets'] ?? null;
        if (!is_array($offsets)) {
            throw new RuntimeException('translatable_fields.overlay_uid_offsets must be an object.');
        }
        $normalisedOffsets = [];
        foreach ($offsets as $key => $perLang) {
            if ($key === 'description' || !is_array($perLang)) {
                continue;
            }
            $bucket = [];
            foreach ($perLang as $langUid => $value) {
                $bucket[(int) $langUid] = (int) $value;
            }
            $normalisedOffsets[(string) $key] = $bucket;
        }
        if ($normalisedOffsets === []) {
            throw new RuntimeException('translatable_fields.overlay_uid_offsets must define at least one offset bucket.');
        }

        $tables = $registry['tables'] ?? null;
        if (!is_array($tables) || $tables === []) {
            throw new RuntimeException('translatable_fields.tables must be a non-empty object.');
        }
        $normalisedTables = [];
        foreach ($tables as $tableName => $spec) {
            if (!is_string($tableName) || $tableName === '' || !is_array($spec)) {
                throw new RuntimeException('translatable_fields.tables entries must be objects keyed by table name.');
            }
            $fields = $spec['fields'] ?? null;
            if (!is_array($fields) || $fields === []) {
                throw new RuntimeException(sprintf('translatable_fields.tables.%s.fields must be a non-empty list.', $tableName));
            }
            $parentField = $spec['parent_field'] ?? null;
            if (!is_string($parentField) || $parentField === '') {
                throw new RuntimeException(sprintf('translatable_fields.tables.%s.parent_field is required.', $tableName));
            }
            $offsetKey = $spec['uid_offset_key'] ?? 'domain_records';
            if (!is_string($offsetKey) || !isset($normalisedOffsets[$offsetKey])) {
                throw new RuntimeException(sprintf('translatable_fields.tables.%s.uid_offset_key references an unknown offset bucket: %s', $tableName, (string) $offsetKey));
            }

            $copyFields = $spec['copy_fields'] ?? [];
            if (!is_array($copyFields)) {
                throw new RuntimeException(sprintf('translatable_fields.tables.%s.copy_fields must be a list when set.', $tableName));
            }

            $falRaw = $spec['fal'] ?? [];
            if (!is_array($falRaw)) {
                throw new RuntimeException(sprintf('translatable_fields.tables.%s.fal must be a list when set.', $tableName));
            }
            $fal = [];
            foreach ($falRaw as $falSpec) {
                if (!is_array($falSpec)) {
                    continue;
                }
                $fieldname = (string) ($falSpec['fieldname'] ?? '');
                $countField = (string) ($falSpec['count_field'] ?? $fieldname);
                if ($fieldname === '') {
                    continue;
                }
                $fal[] = [
                    'fieldname' => $fieldname,
                    'count_field' => $countField !== '' ? $countField : $fieldname,
                ];
            }

            $normalisedTables[$tableName] = [
                'fields' => array_values(array_map('strval', $fields)),
                'copy_fields' => array_values(array_map('strval', $copyFields)),
                'parent_field' => $parentField,
                'uid_offset_key' => $offsetKey,
                'regenerate_slug' => (bool) ($spec['regenerate_slug'] ?? false),
                'fal' => $fal,
            ];
        }

        return new self(
            languageUidMap: $normalisedLangMap,
            tables: $normalisedTables,
            overlayUidOffsets: $normalisedOffsets,
        );
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException When the table is not listed as translatable.
     */
    public function fieldsFor(string $table): array
    {
        return $this->tableSpec($table)['fields'];
    }

    /**
     * Non-translatable fields copied 1:1 from the default-language source row.
     *
     * @return list<string>
     */
    public function copyFieldsFor(string $table): array
    {
        return $this->tableSpec($table)['copy_fields'];
    }

    public function parentFieldFor(string $table): string
    {
        return $this->tableSpec($table)['parent_field'];
    }

    public function shouldRegenerateSlug(string $table): bool
    {
        return $this->tableSpec($table)['regenerate_slug'];
    }

    /**
     * @return list<array{fieldname: string, count_field: string}>
     */
    public function falSpecsFor(string $table): array
    {
        return $this->tableSpec($table)['fal'];
    }

    public function isoCodeFor(int $languageUid): string
    {
        if (!isset($this->languageUidMap[$languageUid])) {
            throw new RuntimeException(sprintf('Unknown sys_language_uid: %d', $languageUid));
        }

        return $this->languageUidMap[$languageUid];
    }

    /**
     * @return list<int>
     */
    public function targetLanguageUids(): array
    {
        $uids = array_keys($this->languageUidMap);
        sort($uids);

        return $uids;
    }

    /**
     * @return array{
     *     fields: list<string>,
     *     copy_fields: list<string>,
     *     parent_field: string,
     *     uid_offset_key: string,
     *     regenerate_slug: bool,
     *     fal: list<array{fieldname: string, count_field: string}>
     * }
     */
    private function tableSpec(string $table): array
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException(sprintf('Table %s is not in the translatable_fields registry.', $table));
        }

        return $this->tables[$table];
    }
}
