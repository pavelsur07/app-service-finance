<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Wildberries\CostCalculator\WbLoyaltyProgramFeeCalculator;
use App\Marketplace\Wildberries\Domain\WbCostCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbLoyaltyProgramFeeCalculatorTest extends TestCase
{
    private const string OPERATION = 'Стоимость участия в программе лояльности';

    public function testCamelCaseRowFromProductionBecomesCharge(): void
    {
        /** @var list<array<string, mixed>> $entries в форме интерфейса нет category_name, его читает процессор */
        $entries = (new WbLoyaltyProgramFeeCalculator())->calculate($this->camelRow('112.24'), null);

        self::assertCount(1, $entries);
        self::assertSame('wb_loyalty_program_fee', $entries[0]['category_code']);
        self::assertSame('Стоимость участия в программе лояльности', $entries[0]['category_name']);
        self::assertSame('112.24', $entries[0]['amount']);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
        self::assertSame('wb:3137360497746:wb_loyalty_program_fee', $entries[0]['external_id']);
        self::assertEquals(new \DateTimeImmutable('2026-08-28T16:02:26Z'), $entries[0]['cost_date']);
        self::assertSame('Комиссия WB за участие в программе лояльности', $entries[0]['description']);
        self::assertNull($entries[0]['product']);
    }

    public function testSnakeCaseRowMatchesCamelCase(): void
    {
        $entries = (new WbLoyaltyProgramFeeCalculator())->calculate([
            'supplier_oper_name' => self::OPERATION,
            'rrd_id' => 3137360497746,
            'sale_dt' => '2026-08-28T16:02:26Z',
            'cashback_commission_change' => 112.24,
        ], null);

        self::assertCount(1, $entries);
        self::assertSame('112.24', $entries[0]['amount']);
        self::assertArrayHasKey('operation_type', $entries[0]);
        self::assertSame(MarketplaceCostOperationType::CHARGE, $entries[0]['operation_type']);
        self::assertSame('wb:3137360497746:wb_loyalty_program_fee', $entries[0]['external_id']);
    }

    public function testNegativeAmountIsStorno(): void
    {
        $entries = (new WbLoyaltyProgramFeeCalculator())->calculate($this->camelRow('-42.09'), null);

        self::assertCount(1, $entries);
        self::assertSame('42.09', $entries[0]['amount']);
        self::assertArrayHasKey('operation_type', $entries[0]);
        self::assertSame(MarketplaceCostOperationType::STORNO, $entries[0]['operation_type']);
    }

    public function testZeroOrMissingAmountIsRecognizedButProducesNoCost(): void
    {
        $calculator = new WbLoyaltyProgramFeeCalculator();
        $missing = $this->camelRow('0');
        unset($missing['cashbackCommissionChange']);

        foreach ([$this->camelRow('0'), $this->camelRow(''), $missing] as $row) {
            self::assertTrue($calculator->supports($row));
            self::assertSame([], $calculator->calculate($row, null));
        }
    }

    #[DataProvider('supportedNames')]
    public function testSupportsOperationNameVariants(string $name): void
    {
        self::assertTrue((new WbLoyaltyProgramFeeCalculator())->supports(['sellerOperName' => $name]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedNames(): iterable
    {
        yield 'as in report' => [self::OPERATION];
        yield 'case and spaces' => ['  стоимость  участия в программе Лояльности '];
    }

    #[DataProvider('foreignNames')]
    public function testDoesNotSupportOtherOperations(string $name): void
    {
        self::assertFalse((new WbLoyaltyProgramFeeCalculator())->supports(['sellerOperName' => $name]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignNames(): iterable
    {
        yield 'review points withheld' => ['Сумма баллов, удержанных в рамках акции "Баллы за отзывы"'];
        yield 'loyalty discount compensation' => ['Компенсация скидки по программе лояльности'];
        yield 'deduction' => ['Удержание'];
        yield 'empty' => [''];
    }

    public function testCategoryIsInCatalogUnderPromotion(): void
    {
        self::assertArrayHasKey('wb_loyalty_program_fee', WbCostCategory::byCode());
        $category = WbCostCategory::byCode()['wb_loyalty_program_fee'];

        self::assertSame('Стоимость участия в программе лояльности', $category->name);
        self::assertSame('Продвижение и реклама', $category->widgetGroup);
    }

    /**
     * Строка отчёта WB с прода (отчёт за 28.10.2026), без названия продавца и ИНН.
     *
     * @return array<string, mixed>
     */
    private function camelRow(string $fee): array
    {
        return [
            'sellerOperName' => self::OPERATION,
            'bonusTypeName' => 'Комиссия ВБ за участие в программе лояльности "Баллы за отзывы"',
            'rrdId' => 3137360497746,
            'rrDate' => '2026-10-28',
            'saleDt' => '2026-08-28T16:02:26Z',
            'nmId' => 691183625,
            'subjectName' => 'Простыни',
            'cashbackAmount' => '0',
            'cashbackCommissionChange' => $fee,
            'deduction' => '0',
            'forPay' => '0',
            'retailAmount' => '0',
        ];
    }
}
