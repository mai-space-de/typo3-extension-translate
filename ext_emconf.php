<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Mai Translate',
    'description' => 'Backend translation extension using DeepL or OpenAI to translate TYPO3 content records. Integrates with the TYPO3 backend translation workflow.',
    'category' => 'module',
    'author' => 'Maispace',
    'author_email' => '',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
