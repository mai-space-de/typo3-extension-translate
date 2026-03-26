<?php

declare(strict_types = 1);

namespace Maispace\MaiTranslate\Loader;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Discovers and merges TranslatableTables.php configuration files from all
 * active TYPO3 packages into a single table → fields map.
 *
 * Any active extension or site package can place a file at
 * Configuration/TranslatableTables.php and return an array of
 * ['table_name' => ['field1', 'field2']] to register additional tables or
 * extend existing ones with more fields.
 *
 * The merged result is stored in the 'translate_tables' cache so the
 * filesystem scan only runs once after a system cache flush.
 */
final class TranslatableTablesLoader
{
    private const CACHE_KEY = 'translatable_tables';
    private const CONFIG_FILENAME = 'TranslatableTables.php';

    private FrontendInterface $cache;

    /** In-process cache so repeated calls within the same request skip cache I/O. */
    private ?array $localCache = null;

    public function __construct(
        private readonly PackageManager $packageManager,
        CacheManager $cacheManager,
    ) {
        $this->cache = $cacheManager->getCache('translate_tables');
    }

    /**
     * Returns the merged map of table → translatable field names.
     *
     * Fields contributed by multiple packages for the same table are combined
     * and de-duplicated. The result is stored in the 'translate_tables' cache
     * (persists across requests) and in a local property (avoids repeated
     * cache lookups within the same request).
     *
     * @return array<string, list<string>>
     */
    public function getTranslatableTables(): array
    {
        if ($this->localCache !== null) {
            return $this->localCache;
        }

        if ($this->cache->has(self::CACHE_KEY)) {
            return $this->localCache = (array)$this->cache->get(self::CACHE_KEY);
        }

        $tables = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $configFile = $package->getPackagePath() . 'Configuration/' . self::CONFIG_FILENAME;

            if (!file_exists($configFile)) {
                continue;
            }

            $config = require $configFile;

            if (!is_array($config)) {
                continue;
            }

            foreach ($config as $table => $fields) {
                if (!is_string($table) || !is_array($fields)) {
                    continue;
                }

                if (!isset($tables[$table])) {
                    $tables[$table] = [];
                }

                // Union field lists, preserving insertion order and avoiding duplicates.
                $tables[$table] = array_values(
                    array_unique(array_merge($tables[$table], array_filter($fields, 'is_string')))
                );
            }
        }

        $this->cache->set(self::CACHE_KEY, $tables);

        return $this->localCache = $tables;
    }
}
