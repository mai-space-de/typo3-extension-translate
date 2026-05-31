<?php

declare(strict_types=1);

namespace Maispace\MaiTranslate\Tests\Unit\Hydration;

use Maispace\MaiTranslate\Hydration\TranslationFieldMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TranslationFieldMapTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function registryFixture(): array
    {
        return [
            'language_uid_map' => ['1' => 'en', '2' => 'uk', '3' => 'ar'],
            'overlay_uid_offsets' => [
                'description' => 'should be ignored',
                'tt_content' => ['1' => 10000, '2' => 20000, '3' => 30000],
                'domain_records' => ['1' => 1000, '2' => 2000, '3' => 3000],
            ],
            'tables' => [
                'tt_content' => [
                    'fields' => ['header', 'bodytext'],
                    'parent_field' => 'l18n_parent',
                    'uid_offset_key' => 'tt_content',
                ],
                'tx_maifaq_faq' => [
                    'fields' => ['question', 'answer'],
                    'parent_field' => 'l10n_parent',
                    'uid_offset_key' => 'domain_records',
                ],
            ],
        ];
    }

    #[Test]
    public function fromArrayLoadsAllTablesAndLanguages(): void
    {
        $map = TranslationFieldMap::fromArray(self::registryFixture());

        self::assertSame(['tt_content', 'tx_maifaq_faq'], $map->tableNames());
        self::assertSame([1, 2, 3], $map->targetLanguageUids());
        self::assertSame('en', $map->isoCodeFor(1));
        self::assertSame('ar', $map->isoCodeFor(3));
    }

    #[Test]
    public function fieldsForReturnsConfiguredFields(): void
    {
        $map = TranslationFieldMap::fromArray(self::registryFixture());

        self::assertSame(['header', 'bodytext'], $map->fieldsFor('tt_content'));
        self::assertSame(['question', 'answer'], $map->fieldsFor('tx_maifaq_faq'));
    }

    #[Test]
    public function parentFieldDistinguishesTtContentFromDomainTables(): void
    {
        $map = TranslationFieldMap::fromArray(self::registryFixture());

        self::assertSame('l18n_parent', $map->parentFieldFor('tt_content'));
        self::assertSame('l10n_parent', $map->parentFieldFor('tx_maifaq_faq'));
    }

    #[Test]
    public function fieldsForThrowsForUnknownTable(): void
    {
        $map = TranslationFieldMap::fromArray(self::registryFixture());

        $this->expectException(RuntimeException::class);
        $map->fieldsFor('tx_unknown');
    }

    #[Test]
    public function isoCodeForThrowsForUnknownLanguage(): void
    {
        $map = TranslationFieldMap::fromArray(self::registryFixture());

        $this->expectException(RuntimeException::class);
        $map->isoCodeFor(42);
    }

    #[Test]
    public function fromArrayRejectsMissingLanguageMap(): void
    {
        $registry = self::registryFixture();
        unset($registry['language_uid_map']);

        $this->expectException(RuntimeException::class);
        TranslationFieldMap::fromArray($registry);
    }

    #[Test]
    public function fromArrayRejectsEmptyFieldsList(): void
    {
        $registry = self::registryFixture();
        $registry['tables']['tt_content']['fields'] = [];

        $this->expectException(RuntimeException::class);
        TranslationFieldMap::fromArray($registry);
    }

    #[Test]
    public function fromArrayRejectsMissingParentField(): void
    {
        $registry = self::registryFixture();
        unset($registry['tables']['tt_content']['parent_field']);

        $this->expectException(RuntimeException::class);
        TranslationFieldMap::fromArray($registry);
    }

    #[Test]
    public function fromArrayRejectsUnknownOffsetBucket(): void
    {
        $registry = self::registryFixture();
        $registry['tables']['tt_content']['uid_offset_key'] = 'does_not_exist';

        $this->expectException(RuntimeException::class);
        TranslationFieldMap::fromArray($registry);
    }

    #[Test]
    public function fromFileLoadsTranslatableFieldsBlock(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'mfm');
        self::assertIsString($temp);
        try {
            file_put_contents(
                $temp,
                json_encode(['translatable_fields' => self::registryFixture()], JSON_THROW_ON_ERROR),
            );
            $map = TranslationFieldMap::fromFile($temp);
            self::assertSame(['tt_content', 'tx_maifaq_faq'], $map->tableNames());
        } finally {
            @unlink($temp);
        }
    }

    #[Test]
    public function fromFileThrowsWhenTranslatableFieldsMissing(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'mfm');
        self::assertIsString($temp);
        try {
            file_put_contents($temp, json_encode(['something_else' => true], JSON_THROW_ON_ERROR));
            $this->expectException(RuntimeException::class);
            TranslationFieldMap::fromFile($temp);
        } finally {
            @unlink($temp);
        }
    }

    #[Test]
    public function fromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(RuntimeException::class);
        TranslationFieldMap::fromFile('/no/such/file.json');
    }

    #[Test]
    public function fromFileThrowsOnInvalidJson(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'mfm');
        self::assertIsString($temp);
        try {
            file_put_contents($temp, '{not valid json');
            $this->expectException(RuntimeException::class);
            TranslationFieldMap::fromFile($temp);
        } finally {
            @unlink($temp);
        }
    }
}
