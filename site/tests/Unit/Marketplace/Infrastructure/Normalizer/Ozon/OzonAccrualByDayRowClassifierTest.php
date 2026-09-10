<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Normalizer\Ozon;

use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonAccrualByDayRowClassifier;
use App\Marketplace\Infrastructure\Normalizer\Ozon\OzonReportRowClassifier;
use App\Marketplace\Infrastructure\Normalizer\RowClassifierRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Разбор строк by-day. Кейсы берутся из обезличенной фикстуры Stage 1 —
 * это реальная форма ответа Ozon, а не выдуманная.
 */
final class OzonAccrualByDayRowClassifierTest extends TestCase
{
    public function testSaleIsRecognisedByPositiveSaleAmount(): void
    {
        self::assertSame(StagingRecordType::SALE, $this->classify($this->case(0)));
    }

    public function testReturnIsRecognisedByNegativeSaleAmount(): void
    {
        // Признак возврата — знак sale_amount. Знак вычисленного количества не
        // годится: у возврата отрицательны и sale_amount, и seller_price, их
        // частное даёт +1.
        $return = $this->case(1);
        self::assertLessThan(0, (float) $return['posting']['products'][0]['commission']['sale_amount']['amount']);
        self::assertSame(StagingRecordType::RETURN, $this->classify($return));
    }

    public function testPostingWithoutRevenueIsNotASale(): void
    {
        // 191 товар из 234 в июньской выгрузке не несёт sale_amount вовсе:
        // это отправление без выручки, только услуги. Заводить по нему продажу
        // с нулевой суммой нельзя.
        $noRevenue = $this->case(2);
        self::assertArrayNotHasKey('sale_amount', $noRevenue['posting']['products'][0]['commission'] ?? []);
        self::assertSame(StagingRecordType::OTHER, $this->classify($noRevenue));
    }

    public function testItemAndNonItemFeesAreNotSalesOrReturns(): void
    {
        self::assertSame(StagingRecordType::OTHER, $this->classify($this->case(3)));
        self::assertSame(StagingRecordType::OTHER, $this->classify($this->case(4)));
    }

    public function testClassifierClaimsOnlyItsOwnFormat(): void
    {
        $classifier = new OzonAccrualByDayRowClassifier();

        self::assertTrue($classifier->supports(MarketplaceType::OZON, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
        self::assertFalse($classifier->supports(MarketplaceType::OZON, MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3));
        self::assertFalse($classifier->supports(MarketplaceType::OZON, null));
        self::assertFalse($classifier->supports(MarketplaceType::WILDBERRIES, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
    }

    public function testRegistryDoesNotShadowByDayClassifierWithLegacyOne(): void
    {
        // Реестр берёт первый подошедший, и легаси-классификатор стоит раньше.
        $legacy = new OzonReportRowClassifier();
        $byDay = new OzonAccrualByDayRowClassifier();
        $registry = new RowClassifierRegistry([$legacy, $byDay]);

        self::assertSame($byDay, $registry->get(MarketplaceType::OZON, MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY));
        self::assertSame($legacy, $registry->get(MarketplaceType::OZON, MarketplaceRawFormat::OZON_TRANSACTION_LIST_V3));
        self::assertSame($legacy, $registry->get(MarketplaceType::OZON), 'Без формата — прежнее поведение.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function classify(array $row): StagingRecordType
    {
        return (new OzonAccrualByDayRowClassifier())->classify($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function case(int $index): array
    {
        $path = __DIR__.'/../../../../../Fixtures/Marketplace/Ozon/accrual_by_day_cases.json';
        $fixture = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture['accruals'] ?? null);

        return $fixture['accruals'][$index];
    }
}
