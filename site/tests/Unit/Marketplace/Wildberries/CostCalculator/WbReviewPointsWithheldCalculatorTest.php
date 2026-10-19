<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\CostCalculator\WbReviewPointsWithheldCalculator;
use App\Marketplace\Wildberries\Domain\WbCostCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbReviewPointsWithheldCalculatorTest extends TestCase
{
    private const string OPERATION = 'Сумма баллов, удержанных в рамках акции "Баллы за отзывы"';

    public function testCamelCaseRowFromProductionBecomesCharge(): void
    {
        /** @var list<array<string, mixed>> $entries в форме интерфейса нет category_name, его читает процессор */
        $entries = (new WbReviewPointsWithheldCalculator())->calculate($this->camelRow('350'), null);

        self::assertCount(1, $entries);
        self::assertSame('wb_review_points_withheld', $entries[0]['category_code']);
        self::assertSame('Баллы за отзывы (удержание)', $entries[0]['category_name']);
        self::assertSame('350', $entries[0]['amount']);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
        self::assertSame('wb:3136817525010:wb_review_points_withheld', $entries[0]['external_id']);
        self::assertEquals(new \DateTimeImmutable('2026-10-18T07:43:49Z'), $entries[0]['cost_date']);
        self::assertSame(
            'Баллы за отзывы: удержание внутри маркетплейса (по сути реклама/маркетинг)',
            $entries[0]['description'],
        );
        self::assertNull($entries[0]['product']);
    }

    public function testSnakeCaseRowMatchesCamelCase(): void
    {
        $entries = (new WbReviewPointsWithheldCalculator())->calculate([
            'supplier_oper_name' => self::OPERATION,
            'rrd_id' => 3136817525010,
            'sale_dt' => '2026-10-18T07:43:49Z',
            'cashback_amount' => 350,
        ], null);

        self::assertCount(1, $entries);
        self::assertSame('350', $entries[0]['amount']);
        self::assertArrayHasKey('operation_type', $entries[0]);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
        self::assertSame('wb:3136817525010:wb_review_points_withheld', $entries[0]['external_id']);
    }

    public function testNegativeAmountIsStorno(): void
    {
        $entries = (new WbReviewPointsWithheldCalculator())->calculate($this->camelRow('-400'), null);

        self::assertCount(1, $entries);
        self::assertSame('400', $entries[0]['amount']);
        self::assertArrayHasKey('operation_type', $entries[0]);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entries[0]['operation_type']);
    }

    public function testZeroOrMissingAmountIsRecognizedButProducesNoCost(): void
    {
        $calculator = new WbReviewPointsWithheldCalculator();
        $missing = $this->camelRow('0');
        unset($missing['cashbackAmount']);

        foreach ([$this->camelRow('0'), $this->camelRow(''), $missing] as $row) {
            self::assertTrue($calculator->supports($row));
            self::assertSame([], $calculator->calculate($row, null));
        }
    }

    #[DataProvider('supportedNames')]
    public function testSupportsOperationNameVariants(string $name): void
    {
        self::assertTrue((new WbReviewPointsWithheldCalculator())->supports(['sellerOperName' => $name]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedNames(): iterable
    {
        yield 'as in report' => [self::OPERATION];
        yield 'guillemets' => ['Сумма баллов, удержанных в рамках акции «Баллы за отзывы»'];
        yield 'extra spaces' => ['  Сумма баллов,  удержанных в рамках акции "Баллы за отзывы" '];
    }

    #[DataProvider('foreignNames')]
    public function testDoesNotSupportOtherOperations(string $name): void
    {
        self::assertFalse((new WbReviewPointsWithheldCalculator())->supports(['sellerOperName' => $name]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignNames(): iterable
    {
        yield 'advance for the service' => ['Аванс за услугу "Баллы за отзывы"'];
        yield 'deduction' => ['Удержание'];
        yield 'sale' => ['Продажа'];
        yield 'empty' => [''];
    }

    public function testCategoryIsInCatalogUnderPromotion(): void
    {
        self::assertArrayHasKey('wb_review_points_withheld', WbCostCategory::byCode());
        $category = WbCostCategory::byCode()['wb_review_points_withheld'];

        self::assertSame('Баллы за отзывы (удержание)', $category->name);
        self::assertSame('Продвижение и реклама', $category->widgetGroup);
    }

    /**
     * Строка отчёта WB с прода (19.10.2026), без названия продавца и ИНН.
     *
     * @return array<string, mixed>
     */
    private function camelRow(string $cashbackAmount): array
    {
        return [
            'sellerOperName' => self::OPERATION,
            'rrdId' => 3136817525010,
            'rrDate' => '2026-10-18',
            'saleDt' => '2026-10-18T07:43:49Z',
            'nmId' => 687862798,
            'subjectName' => 'Наволочки',
            'docTypeName' => '',
            'cashbackAmount' => $cashbackAmount,
            'cashbackDiscount' => '0',
            'deduction' => '0',
            'forPay' => '0',
            'retailAmount' => '0',
        ];
    }
}
