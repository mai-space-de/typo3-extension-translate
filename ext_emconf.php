<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Translate',
    'description' => 'Adds a translate button to TYPO3 backend for pages and content elements using DeepL or OpenAI.',
    'version' => '13.0.0',
    'state' => 'stable',
    'category' => 'templates',
    'author' => 'Joel Maximilian Mai',
    'author_email' => 'joel@maispace.de',
    'author_company' => 'Maispace',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
    ],
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'autoload' => [
        'psr-4' => [
            'Maispace\\Translate\\' => 'Classes',
        ],
    ],
];
