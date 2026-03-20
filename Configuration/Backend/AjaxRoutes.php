<?php

return [
    'ajax_translate_record' => [
        'path' => '/ajax/translate/record',
        'target' => \Maispace\MaiTranslate\Controller\TranslateController::class . '::translateAction',
    ],
];
