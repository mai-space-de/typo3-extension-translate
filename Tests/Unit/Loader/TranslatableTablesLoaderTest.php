<?php

declare(strict_types = 1);

namespace Maispace\MaiTranslate\Tests\Unit\Loader;

use Maispace\MaiTranslate\Loader\TranslatableTablesLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

#[CoversClass(TranslatableTablesLoader::class)]
final class TranslatableTablesLoaderTest extends TestCase
{
    private PackageManager&MockObject $packageManager;
    private FrontendInterface&MockObject $cache;
    private CacheManager&MockObject $cacheManager;

    protected function setUp(): void
    {
        $this->packageManager = $this->createMock(PackageManager::class);
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->cacheManager = $this->createMock(CacheManager::class);
        $this->cacheManager->method('getCache')->with('translate_tables')->willReturn($this->cache);
    }

    #[Test]
    public function getTranslatableTablesReturnsCachedResult(): void
    {
        $cached = ['tt_content' => ['header', 'bodytext']];
        $this->cache->method('has')->with('translatable_tables')->willReturn(true);
        $this->cache->method('get')->with('translatable_tables')->willReturn($cached);

        $loader = new TranslatableTablesLoader($this->packageManager, $this->cacheManager);

        self::assertSame($cached, $loader->getTranslatableTables());
    }

    #[Test]
    public function getTranslatableTablesReturnsSameInstanceOnSecondCall(): void
    {
        $cached = ['tt_content' => ['header']];
        $this->cache->method('has')->with('translatable_tables')->willReturn(true);
        $this->cache->method('get')->with('translatable_tables')->willReturn($cached);

        $loader = new TranslatableTablesLoader($this->packageManager, $this->cacheManager);

        // Second call should use in-process cache, so cache->get is only called once.
        $this->cache->expects(self::once())->method('get');
        $loader->getTranslatableTables();
        $loader->getTranslatableTables();
    }

    #[Test]
    public function getTranslatableTablesScansPackagesWhenCacheMisses(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->cache->expects(self::once())->method('set');
        $this->packageManager->method('getActivePackages')->willReturn([]);

        $loader = new TranslatableTablesLoader($this->packageManager, $this->cacheManager);

        self::assertSame([], $loader->getTranslatableTables());
    }

    #[Test]
    public function getTranslatableTablesDeduplicatesFields(): void
    {
        $this->cache->method('has')->willReturn(false);
        $this->cache->method('set');

        $pkgA = $this->createConfiguredMock(PackageInterface::class, [
            'getPackagePath' => $this->createTempPackageWithConfig(
                ['tt_content' => ['header', 'bodytext']]
            ),
        ]);
        $pkgB = $this->createConfiguredMock(PackageInterface::class, [
            'getPackagePath' => $this->createTempPackageWithConfig(
                ['tt_content' => ['bodytext', 'subheader']]
            ),
        ]);

        $this->packageManager->method('getActivePackages')->willReturn([$pkgA, $pkgB]);

        $loader = new TranslatableTablesLoader($this->packageManager, $this->cacheManager);
        $tables = $loader->getTranslatableTables();

        self::assertSame(['header', 'bodytext', 'subheader'], $tables['tt_content']);
    }

    /**
     * Creates a temporary directory containing Configuration/TranslatableTables.php
     * that returns the given array, and returns the package path (with trailing slash).
     */
    private function createTempPackageWithConfig(array $config): string
    {
        $dir = sys_get_temp_dir() . '/typo3_translate_test_' . uniqid('', true);
        mkdir($dir . '/Configuration', 0o777, true);
        file_put_contents(
            $dir . '/Configuration/TranslatableTables.php',
            '<?php return ' . var_export($config, true) . ';'
        );

        return $dir . '/';
    }
}
