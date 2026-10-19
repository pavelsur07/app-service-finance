<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategory;
use App\Ingestion\Application\Source\Ozon\OzonAccrualDictionaryCategoryFactory;
use App\Ingestion\Enum\TransactionType;
use App\Tests\Builders\Ingestion\OzonAccrualTypeBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonAccrualDictionaryCategoryFactoryTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../../../Fixtures/Marketplace/Ozon/accrual_types.json';

    #[DataProvider('names')]
    public function testCodeFromOzonName(string $name, string $expectedCode): void
    {
        self::assertSame($expectedCode, OzonAccrualDictionaryCategoryFactory::codeFor($name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'camel case' => ['AnalyticsPlus', 'ozon_analytics_plus'];
        yield 'space and hyphen' => ['Drop-Off Agent', 'ozon_drop_off_agent'];
        yield 'acronym with digit' => ['B2CContainerPacking', 'ozon_b2c_container_packing'];
        yield 'leading acronym' => ['RfbsServiceFee', 'ozon_rfbs_service_fee'];
        yield 'all caps acronym' => ['CISPacking', 'ozon_cis_packing'];
        yield 'digit before word' => ['Version2Fee', 'ozon_version2_fee'];
        yield 'non latin falls back to hash' => ['Тест', 'ozon_'.substr(hash('sha256', 'Тест'), 0, 8)];
    }

    public function testLabelFromDescriptionAndCategoryStaysUnknown(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->withTypeId(80)->withName('VideoCover')->withDescription('Видеообложка')->build();

        $category = (new OzonAccrualDictionaryCategoryFactory())->create($type, TransactionType::OTHER);

        self::assertSame('ozon_video_cover', $category->code);
        self::assertSame('Видеообложка', $category->label);
        self::assertSame(OzonAccrualCategory::UNKNOWN_GROUP, $category->group);
        self::assertSame(TransactionType::OTHER, $category->transactionType);
        self::assertFalse($category->known);
        self::assertSame(['VideoCover'], $category->aliases);
    }

    public function testLabelFallsBackToNameWithoutDescription(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->withTypeId(127)->withName('AnalyticsPlus')->withDescription(null)->build();

        $category = (new OzonAccrualDictionaryCategoryFactory())->create($type, TransactionType::FEE);

        self::assertSame('AnalyticsPlus', $category->label);
        self::assertSame(TransactionType::FEE, $category->transactionType);
    }

    public function testGeneratedCodesAreUniqueAndNeverTakeCatalogCode(): void
    {
        /** @var array{accrual_types: list<array{id: int, name: string, description: string}>} $fixture */
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, flags: \JSON_THROW_ON_ERROR);
        $factory = new OzonAccrualDictionaryCategoryFactory();

        $codes = [];
        foreach ($fixture['accrual_types'] as $row) {
            $type = OzonAccrualTypeBuilder::aType()->withTypeId($row['id'])->withName($row['name'])->withDescription($row['description'])->build();
            $category = $factory->create($type, TransactionType::OTHER);

            self::assertFalse($category->known, $row['name']);
            self::assertSame(TransactionType::OTHER, $category->transactionType, $row['name']);
            self::assertNull(OzonAccrualCategory::findByCode($category->code), sprintf('%s: code %s is taken by the catalog', $row['name'], $category->code));
            self::assertLessThanOrEqual(100, strlen($category->code), $row['name']);
            self::assertArrayNotHasKey($category->code, $codes, sprintf('%s collides with %s', $row['name'], $codes[$category->code] ?? ''));
            $codes[$category->code] = $row['name'];
        }

        self::assertCount(126, $codes);
    }

    public function testCodeTakenByCatalogGetsTypeIdSuffix(): void
    {
        $type = OzonAccrualTypeBuilder::aType()->withTypeId(69)->withName('SaleCommission')->withDescription('Вознаграждение за продажу')->build();

        $category = (new OzonAccrualDictionaryCategoryFactory())->create($type, TransactionType::FEE);

        self::assertSame('ozon_sale_commission_69', $category->code);
        self::assertFalse($category->known);
        self::assertSame(TransactionType::FEE, $category->transactionType);
    }

    /**
     * Имя до 255 и описание до 500 символов в справочнике не должны давать код
     * и название длиннее колонок маппинга (100 и 255) — иначе подтверждение падает.
     */
    public function testLongNameAndDescriptionFitMappingColumns(): void
    {
        $factory = new OzonAccrualDictionaryCategoryFactory();
        $first = $factory->create(
            OzonAccrualTypeBuilder::aType()->withTypeId(4000000001)->withName(str_repeat('A', 254).'B')->withDescription(str_repeat('я', 500))->build(),
            TransactionType::FEE,
        );
        $second = $factory->create(
            OzonAccrualTypeBuilder::aType()->withTypeId(4000000002)->withName(str_repeat('A', 254).'C')->withDescription(null)->build(),
            TransactionType::FEE,
        );

        self::assertLessThanOrEqual(OzonAccrualDictionaryCategoryFactory::CODE_MAX_LENGTH - 12, \strlen($first->code));
        self::assertNotSame($first->code, $second->code);
        self::assertSame(OzonAccrualDictionaryCategoryFactory::LABEL_MAX_LENGTH, mb_strlen($first->label));
        self::assertSame(OzonAccrualDictionaryCategoryFactory::LABEL_MAX_LENGTH, mb_strlen($second->label));
        self::assertLessThanOrEqual(OzonAccrualDictionaryCategoryFactory::CODE_MAX_LENGTH, \strlen($first->code.'_4000000001'));
    }
}
