<?php

/**
 * Example TranslatableTables configuration.
 *
 * To extend the set of translatable tables, create a file named
 * TranslatableTables.php (NOT TranslatableTablesExample.php) in the
 * Configuration/ directory of your own extension or site package, and have
 * it return a plain PHP array (as shown below).
 *
 * The array is keyed by TCA table name. Each value is a list of field names
 * to include in translation. Fields from multiple extensions are merged
 * automatically, so you can both extend existing tables with extra fields
 * and register entirely new tables.
 *
 * The merged result is cached in the 'translate_tables' system cache and
 * is invalidated when TYPO3 system caches are cleared.
 *
 * -------------------------------------------------------------------------
 * Copy the return statement below into your new TranslatableTables.php file:
 * -------------------------------------------------------------------------
 *
 * return [
 *     // Register a custom table for translation
 *     'tx_myext_domain_model_product' => [
 *         'name',
 *         'description',
 *         'teaser',
 *     ],
 *
 *     // Extend an existing table with additional fields
 *     'tt_content' => [
 *         'tx_myext_custom_field',
 *     ],
 * ];
 */
