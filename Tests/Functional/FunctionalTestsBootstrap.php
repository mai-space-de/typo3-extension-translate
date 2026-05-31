<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full license information please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Bootstrap file for mai_translate functional tests.
 *
 * Defines ORIGINAL_ROOT so that FunctionalTestCase can locate
 * the project root and set up a test instance.
 *
 * Also loads database credentials from the project's .env file
 * and converts them to the format expected by the testing framework.
 *
 * @see \TYPO3\TestingFramework\Core\Functional\FunctionalTestCase
 */
(static function (): void {
    // Set ORIGINAL_ROOT to project root BEFORE Testbase::defineOriginalRootPath()
    // This file is at: packages/typo3-extension-translate/Tests/Functional/FunctionalTestsBootstrap.php
    // Project root is 4 levels up from this file
    if (!defined('ORIGINAL_ROOT')) {
        define('ORIGINAL_ROOT', dirname(__DIR__, 4) . '/');
    }

    $testbase = new \TYPO3\TestingFramework\Core\Testbase();

    // Use SQLite for functional tests to avoid MySQL connection issues
    // This is especially important in DDEV/containerized environments
    putenv('typo3DatabaseDriver=pdo_sqlite');
    putenv('typo3DatabaseName=:memory:');

    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
