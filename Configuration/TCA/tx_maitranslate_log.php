<?php

declare(strict_types=1);

use Maispace\MaiBase\TableConfigurationArray\FieldConfig\InputConfig;
use Maispace\MaiBase\TableConfigurationArray\FieldConfig\NumberConfig;
use Maispace\MaiBase\TableConfigurationArray\FieldConfig\SelectSingleConfig;
use Maispace\MaiBase\TableConfigurationArray\Helper;
use Maispace\MaiBase\TableConfigurationArray\Table;

$lang = Helper::localLangHelperFactory('mai_translate', 'Default/locallang_tca.xlf');

return (new Table($lang('table.tx_maitranslate_log')))
    ->setDefaultConfig()
    ->setLabel('record_table')
    ->setAlternativeLabelFields('record_uid, field')
    ->setIconFile('EXT:mai_translate/Resources/Public/Icons/tx_maitranslate_log.svg')
    ->setDefaultSorting('ORDER BY crdate DESC')
    ->recordsAreOnlyAllowedInRoot()
    ->setAccessableOnlyByAdmins()
    ->recordsCanOnlyBeRead()
    ->addColumn(
        'record_table',
        $lang('tx_maitranslate_log.record_table'),
        (new InputConfig())->setSize(50)->setMax(255)->setReadOnly()
    )
    ->addColumn(
        'record_uid',
        $lang('tx_maitranslate_log.record_uid'),
        (new NumberConfig())->setFormat('integer')->setReadOnly()
    )
    ->addColumn(
        'field',
        $lang('tx_maitranslate_log.field'),
        (new InputConfig())->setSize(50)->setMax(255)->setReadOnly()
    )
    ->addColumn(
        'source_language',
        $lang('tx_maitranslate_log.source_language'),
        (new InputConfig())->setSize(10)->setMax(10)->setReadOnly()
    )
    ->addColumn(
        'target_language',
        $lang('tx_maitranslate_log.target_language'),
        (new InputConfig())->setSize(10)->setMax(10)->setReadOnly()
    )
    ->addColumn(
        'provider',
        $lang('tx_maitranslate_log.provider'),
        (new SelectSingleConfig())
            ->setItems([
                ['label' => 'DeepL', 'value' => 'deepl'],
                ['label' => 'OpenAI', 'value' => 'openai'],
            ])
            ->setReadOnly()
    )
    ->addColumn(
        'status',
        $lang('tx_maitranslate_log.status'),
        (new SelectSingleConfig())
            ->setItems([
                ['label' => $lang('tx_maitranslate_log.status.success'), 'value' => 'success'],
                ['label' => $lang('tx_maitranslate_log.status.failed'), 'value' => 'failed'],
            ])
            ->setReadOnly()
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
