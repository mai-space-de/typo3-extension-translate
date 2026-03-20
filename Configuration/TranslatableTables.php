<?php

declare(strict_types=1);

/**
 * Default translatable tables and fields for the maispace/translate extension.
 *
 * This file is auto-discovered and merged with matching files from all other
 * active TYPO3 packages. See TranslatableTablesExample.php for instructions
 * on extending this list from your own extension or site package.
 */
return [
    'tt_content' => [
        'header',
        'subheader',
        'bodytext',
        'header_link',
    ],
    'pages' => [
        'title',
        'subtitle',
        'nav_title',
        'abstract',
        'description',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ],
    'sys_file_metadata' => [
        'title',
        'description',
        'caption',
        'alternative',
    ],
    'sys_file_reference' => [
        'title',
        'description',
        'alternative',
        'link',
    ],
];
