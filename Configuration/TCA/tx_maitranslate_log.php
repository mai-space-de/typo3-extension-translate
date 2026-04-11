<?php

declare(strict_types=1);

use Maispace\MaiBase\TableConfigurationArray\Helper;
use Maispace\MaiBase\TableConfigurationArray\Table;

$lang = Helper::localLangHelperFactory('mai_translate', 'Default/locallang_tca.xlf');

return (new Table($lang('table.tx_maitranslate_log')))
    ->setDefaultConfig()
    ->setLabel('record_table')
    ->setAlternativeLabelFields('record_uid, field')
    ->setSearchFields('record_table, field')
    ->setIconFile('EXT:mai_translate/Resources/Public/Icons/tx_maitranslate_log.svg')
    ->setDefaultSorting('ORDER BY crdate DESC')
    ->recordsAreOnlyAllowedInRoot()
    ->setAccessableOnlyByAdmins()
    ->recordsCanOnlyBeRead()
    ->addColumn(
        'record_table',
        $lang('tx_maitranslate_log.record_table'),
        ['type' => 'input', 'size' => 50, 'max' => 255, 'readOnly' => true]
    )
    ->addColumn(
        'record_uid',
        $lang('tx_maitranslate_log.record_uid'),
        ['type' => 'number', 'format' => 'integer', 'readOnly' => true]
    )
    ->addColumn(
        'field',
        $lang('tx_maitranslate_log.field'),
        ['type' => 'input', 'size' => 50, 'max' => 255, 'readOnly' => true]
    )
    ->addColumn(
        'source_language',
        $lang('tx_maitranslate_log.source_language'),
        ['type' => 'input', 'size' => 10, 'max' => 10, 'readOnly' => true]
    )
    ->addColumn(
        'target_language',
        $lang('tx_maitranslate_log.target_language'),
        ['type' => 'input', 'size' => 10, 'max' => 10, 'readOnly' => true]
    )
    ->addColumn(
        'provider',
        $lang('tx_maitranslate_log.provider'),
        [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => 'DeepL', 'value' => 'deepl'],
                ['label' => 'OpenAI', 'value' => 'openai'],
            ],
            'readOnly' => true,
        ]
    )
    ->addColumn(
        'status',
        $lang('tx_maitranslate_log.status'),
        [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $lang('tx_maitranslate_log.status.success'), 'value' => 'success'],
                ['label' => $lang('tx_maitranslate_log.status.failed'), 'value' => 'failed'],
            ],
            'readOnly' => true,
        ]
    )
    ->addPalette(
        'record',
        $lang('palette.record'),
        'record_table, record_uid, field'
    )
    ->addPalette(
        'translation',
        $lang('palette.translation'),
        'source_language, target_language, provider'
    )
    ->addTypeShowItem(
        '0',
        '--palette--;;record, --palette--;;translation, status'
    )
    ->getConfig();
