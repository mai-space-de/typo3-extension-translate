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
 * on each row are subject to translation.
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
     * @param array<int, string>                                                 $languageUidMap   sys_language_uid → ISO code
     * @param array<string, array{fields: list<string>, parent_field: string, uid_offset_key: string}> $tables
     * @param array<string, array<int, int>>                                     $overlayUidOffsets per uid_offset_key + language uid → offset
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

            $normalisedTables[$tableName] = [
                'fields' => array_values(array_map('strval', $fields)),
                'parent_field' => $parentField,
                'uid_offset_key' => $offsetKey,
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
        if (!isset($this->tables[$table])) {
            throw new RuntimeException(sprintf('Table %s is not in the translatable_fields registry.', $table));
        }

        return $this->tables[$table]['fields'];
    }

    public function parentFieldFor(string $table): string
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException(sprintf('Table %s is not in the translatable_fields registry.', $table));
        }

        return $this->tables[$table]['parent_field'];
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
}
