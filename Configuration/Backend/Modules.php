<?php

declare(strict_types=1);

use Maispace\MaiTranslate\Controller\Backend\TranslationLogBackendController;

return [
    'mai_translate' => [
        'parent' => 'web',
        'access' => 'admin',
        'workspaces' => 'online',
        'path' => '/module/mai-translate',
        'iconIdentifier' => 'mai-backend-module',
        'labels' => 'LLL:EXT:mai_translate/Resources/Private/Language/locallang.xlf',
        'extensionName' => 'MaiTranslate',
        'controllerActions' => [
            TranslationLogBackendController::class => ['index'],
        ],
    ],
];
