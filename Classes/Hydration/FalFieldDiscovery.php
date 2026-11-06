<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Hydration;

/**
 * Liest Dateifelder aus der TCA der Tabellen, die bereits übersetzt werden.
 *
 * Eine von Hand gepflegte fal-Liste bleibt damit nur ein Zusatz. Felder mit
 * l10n_mode=exclude teilen sich die Referenz der Standardsprache und bekommen
 * keine eigene Overlay-Zeile.
 */
final class FalFieldDiscovery
{
    /**
     * @param array<string, mixed> $tca
     * @param list<string> $tables
     *
     * @return array<string, list<array{fieldname: string, count_field: string}>>
     */
    public function fileFieldsForTables(array $tca, array $tables): array
    {
        $discovered = [];
        foreach ($tables as $table) {
            $columns = $tca[$table]['columns'] ?? null;
            if (!is_array($columns)) {
                continue;
            }
            $specs = [];
            foreach ($columns as $fieldName => $column) {
                if (!is_string($fieldName) || !$this->isFileField($column)) {
                    continue;
                }
                $specs[] = [
                    'fieldname' => $fieldName,
                    'count_field' => $fieldName,
                ];
            }
            if ($specs !== []) {
                $discovered[$table] = $specs;
            }
        }

        return $discovered;
    }

    private function isFileField(mixed $column): bool
    {
        if (!is_array($column) || ($column['l10n_mode'] ?? '') === 'exclude') {
            return false;
        }
        $config = $column['config'] ?? null;
        if (!is_array($config)) {
            return false;
        }
        $type = (string) ($config['type'] ?? '');
        if ($type === 'file') {
            return true;
        }

        return $type === 'inline'
            && (string) ($config['foreign_table'] ?? '') === 'sys_file_reference';
    }
}
