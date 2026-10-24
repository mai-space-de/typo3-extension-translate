<?php

declare(strict_types=1);

$allIcons = 'EXT:mai_base/Resources/Public/Icons/all-icons/';

return [
    'ext-maispace-mai_translate' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => 'EXT:mai_translate/Resources/Public/Icons/Extension.svg',
    ],
    'mai-translate-module' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => $allIcons . 'interface-text-formatting-text-to-speech-1--speech-automated-translate-language-voice-technology--Streamline-Core.svg',
    ],
    'mai-translate-log' => [
        'provider' => \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
        'source' => $allIcons . 'interface-text-formatting-text-to-speech-1--speech-automated-translate-language-voice-technology--Streamline-Core.svg',
    ],
];
