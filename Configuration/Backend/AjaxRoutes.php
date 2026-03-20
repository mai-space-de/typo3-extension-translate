<?php

return [
    'ajax_translate_record' => [
        'path' => '/ajax/translate/record',
        'target' => \Maispace\Translate\Controller\TranslateController::class . '::translateAction',
    ],
];
