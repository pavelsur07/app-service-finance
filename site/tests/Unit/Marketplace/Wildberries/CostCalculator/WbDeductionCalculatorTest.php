<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries\CostCalculator;

use App\Marketplace\Wildberries\CostCalculator\WbDeductionCalculator;
use App\Marketplace\Wildberries\Domain\WbCostCategory;
use App\Shared\Service\SlugifyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WbDeductionCalculatorTest extends TestCase
{
    public function testMonthlyWarehouseDisposalNamesUseOneStableCategory(): void
    {
        $calculator = new WbDeductionCalculator(new SlugifyService());

        $june = $calculator->calculate($this->deductionRow(
            7001,
            'Отчет об утилизированном товаре (по складу) за июнь 2026',
            17.86,
        ), null);
        $july = $calculator->calculate($this->deductionRow(
            7002,
            'Отчёт об утилизированном товаре (по складу) за июль 2026',
            9.12,
        ), null);

        self::assertCount(1, $june);
        self::assertCount(1, $july);
        self::assertSame('wb_warehouse_disposal', $june[0]['category_code']);
        self::assertSame('wb_warehouse_disposal', $july[0]['category_code']);
        self::assertSame('Отчет об утилизированном товаре (по складу) за июнь 2026', $june[0]['description']);
        self::assertSame('Отчёт об утилизированном товаре (по складу) за июль 2026', $july[0]['description']);
        self::assertSame('wb:7001:wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i', $june[0]['external_id']);
        self::assertSame('wb:7002:wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i', $july[0]['external_id']);
    }

    /**
     * Тексты удержаний дословно с прода. Код, который калькулятор выводит из
     * текста, обязан совпадать с кодом каталога и иметь правило ОПиУ: иначе
     * затрата попадает в аналитику «прочим» и до отчёта не доходит.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function catalogedPromotionDeductions(): iterable
    {
        yield 'WB Медиа' => ['Оказание услуг «WB Медиа»', 'wb_okazanie_uslug_wb_media'];
        yield 'Джем' => ['Предоставление услуг по подписке «Джем»', 'wb_predostavlenie_uslug_po_podpiske_dzhem'];
        yield 'Баллы за отзывы' => ['Аванс за услугу "Баллы за отзывы"', 'wb_avans_za_uslugu_bally_za_otzyvy'];
    }

    #[DataProvider('catalogedPromotionDeductions')]
    public function testPromotionDeductionGetsCatalogCodeWithPlRule(string $reason, string $expectedCode): void
    {
        $calculator = new WbDeductionCalculator(new SlugifyService());

        $entries = $calculator->calculate($this->deductionRow(7101, $reason, 3324.0), null);

        self::assertCount(1, $entries);
        self::assertSame($expectedCode, $entries[0]['category_code']);

        $category = WbCostCategory::byCode()[$expectedCode] ?? null;
        self::assertNotNull($category, sprintf('кода "%s" нет в WbCostCategory', $expectedCode));
        self::assertSame('Продвижение и реклама', $category->widgetGroup);

        $rules = Yaml::parseFile(__DIR__.'/../../../../../config/marketplace/default_cost_mapping.yaml');
        $rulesByCode = array_column($rules['marketplaces']['wildberries']['cost_mappings'], 'pl_code', 'cost_code');
        self::assertSame('PROMO_INTERNAL', $rulesByCode[$expectedCode] ?? null);
    }

    /** @return array<string, mixed> */
    private function deductionRow(int $rrdId, string $reason, float $amount): array
    {
        return [
            'rrd_id' => $rrdId,
            'supplier_oper_name' => 'Удержание',
            'rr_dt' => '2026-08-10',
            'deduction' => $amount,
            'bonus_type_name' => $reason,
        ];
    }
}
