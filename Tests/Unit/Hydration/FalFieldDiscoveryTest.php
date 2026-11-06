<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Hydration;

use Maispace\MaiTranslate\Hydration\FalFieldDiscovery;
use Maispace\MaiTranslate\Hydration\TranslationFieldMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FalFieldDiscoveryTest extends TestCase
{
    #[Test]
    public function fileFieldsIncludeTcaFileColumnsAndSkipExcludedOnes(): void
    {
        $tca = [
            'pages' => [
                'columns' => [
                    'title' => ['config' => ['type' => 'input']],
                    'media' => [
                        'config' => [
                            'type' => 'file',
                            'behaviour' => ['allowLanguageSynchronization' => true],
                        ],
                    ],
                    'tx_maiseo_og_image' => ['config' => ['type' => 'file', 'maxitems' => 1]],
                    'shared_download' => [
                        'l10n_mode' => 'exclude',
                        'config' => ['type' => 'file'],
                    ],
                    'legacy_image' => [
                        'config' => [
                            'type' => 'inline',
                            'foreign_table' => 'sys_file_reference',
                        ],
                    ],
                ],
            ],
        ];

        $discovered = (new FalFieldDiscovery())->fileFieldsForTables($tca, ['pages', 'tt_content']);

        self::assertSame([
            'pages' => [
                ['fieldname' => 'media', 'count_field' => 'media'],
                ['fieldname' => 'tx_maiseo_og_image', 'count_field' => 'tx_maiseo_og_image'],
                ['fieldname' => 'legacy_image', 'count_field' => 'legacy_image'],
            ],
        ], $discovered);
    }

    #[Test]
    public function additionalFalSpecsKeepManualEntriesAndAppendTcaFields(): void
    {
        $map = TranslationFieldMap::fromArray([
            'language_uid_map' => ['1' => 'en'],
            'overlay_uid_offsets' => ['pages' => ['1' => 100]],
            'tables' => [
                'pages' => [
                    'fields' => ['title'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'pages',
                    'fal' => [
                        ['fieldname' => 'tx_maiseo_og_image', 'count_field' => 'tx_maiseo_og_image'],
                    ],
                ],
            ],
        ]);

        $merged = $map->withAdditionalFalSpecs([
            'pages' => [
                ['fieldname' => 'tx_maiseo_og_image', 'count_field' => 'other'],
                ['fieldname' => 'media', 'count_field' => 'media'],
            ],
            'tt_content' => [
                ['fieldname' => 'image', 'count_field' => 'image'],
            ],
        ]);

        self::assertSame([
            ['fieldname' => 'tx_maiseo_og_image', 'count_field' => 'tx_maiseo_og_image'],
            ['fieldname' => 'media', 'count_field' => 'media'],
        ], $merged->falSpecsFor('pages'));
        self::assertSame(['alternative', 'title', 'description'], $merged->falTextFieldsFor('pages'));
    }
}
