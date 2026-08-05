<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Config;

use App\Marketplace\Application\DTO\DefaultCostMappingRule;
use App\Marketplace\Application\DTO\DefaultSaleMappingRule;
use App\Marketplace\Domain\OzonCostCategory;
use App\Marketplace\Domain\WbCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use App\Marketplace\Infrastructure\Provider\DefaultSaleMappingYamlProvider;
use PHPUnit\Framework\TestCase;

/**
 * Гварды на боевые конфиги базового маппинга.
 *
 * Опечатка в cost_code делает правило вечно неприменимым и молча: preview
 * покажет MISSING_COST_CATEGORY, что выглядит как «у компании нет такой
 * затраты». Опечатка в pl_code блокирует apply целиком. Оба случая ловим тут.
 */
final class DefaultMappingConfigTest extends TestCase
{
    /** Категории ОПиУ стандартного дерева, на которые ссылаются конфиги. */
    private const ALLOWED_PL_CODES = [
        'COGS_ACQUIRING',
        'COGS_DELIVERY',
        'COGS_MP_COMMISSION',
        'COGS_PRODUCT_RET',
        'COGS_PRODUCT_REV',
        'COGS_RETURNS_DELIVERY',
        'OPEX_WH_COMPENSATION',
        'OPEX_WH_MP_DEDUCTIONS',
        'OPEX_WH_PENALTIES',
        'OPEX_WH_RECEIVING',
        'OPEX_WH_STORAGE',
        'OVERHEAD_ADMIN_BANK',
        'OVERHEAD_PROD_CERT',
        'PRODUCT_INFRA_FF_SERVICES',
        'PROMO_INTERNAL',
        'REV_NOT_SPP',
        'REV_RETURNS',
        'REV_SPP_RETURNS',
        'REV_SPP_SALES',
    ];

    /** Лимит колонки marketplace_cost_categories.code. */
    private const COST_CODE_MAX_LENGTH = 50;

    /**
     * Коды WB вне каталога WbCostCategory: `advertising` создаётся
     * RestoreMarketplaceCostCategoriesAction (метод приватный, поэтому дублируем),
     * остальные — динамические слаги удержаний из WbDeductionCalculator.
     *
     * Список закрытый намеренно: маска «любой wb_*» пропустила бы опечатку, а
     * опечатка в коде — вечно неприменимое правило и затрата мимо ОПиУ.
     * Помесячные коды утилизации перечислены по различимой букве месяца:
     * слаг режется до 50 байт, от названия месяца остаётся один символ.
     */
    private const EXTRA_WB_CODES = [
        'advertising',
        'wb_avans_za_uslugu_bally_za_otzyvy',
        'wb_predostavlenie_uslug_po_podpiske_dzhem',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_y',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_f',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_m',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_a',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_s',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_o',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_n',
        'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_d',
    ];

    public function testEveryOzonCostCodeExistsInCatalog(): void
    {
        $catalog = array_keys(OzonCostCategory::byCode());

        foreach ($this->rulesFor(MarketplaceType::OZON) as $rule) {
            self::assertContains(
                $rule->getCostCode(),
                $catalog,
                sprintf('Код "%s" не найден в OzonCostCategory — правило никогда не сработает.', $rule->getCostCode()),
            );
        }
    }

    public function testEveryOzonCatalogCodeHasRule(): void
    {
        $mapped = array_map(
            static fn (DefaultCostMappingRule $rule): string => $rule->getCostCode(),
            $this->rulesFor(MarketplaceType::OZON),
        );

        self::assertSame(
            [],
            array_values(array_diff(array_keys(OzonCostCategory::byCode()), $mapped)),
            'Код каталога Ozon без правила базового маппинга: затрата молча не попадёт в ОПиУ.',
        );
    }

    public function testWildberriesCostCodesAreKnownOrDynamicSlugs(): void
    {
        $static = [...array_keys(WbCostCategory::byCode()), ...self::EXTRA_WB_CODES];
        $rules = $this->rulesFor(MarketplaceType::WILDBERRIES);

        self::assertNotEmpty($rules, 'Список правил Wildberries не должен быть пустым.');

        foreach ($rules as $rule) {
            $code = $rule->getCostCode();

            self::assertLessThanOrEqual(
                self::COST_CODE_MAX_LENGTH,
                \strlen($code),
                sprintf('Код "%s" длиннее колонки: реальный код будет обрезан и правило не совпадёт.', $code),
            );

            self::assertContains(
                $code,
                $static,
                sprintf('Код "%s" не из каталога WbCostCategory и не из списка известных динамических слагов.', $code),
            );
        }
    }

    public function testEveryPlCodeIsFromStandardTree(): void
    {
        foreach (MarketplaceType::cases() as $marketplace) {
            foreach ($this->rulesFor($marketplace) as $rule) {
                self::assertContains(
                    $rule->getPlCode(),
                    self::ALLOWED_PL_CODES,
                    sprintf('Неизвестная статья ОПиУ "%s" у правила "%s".', $rule->getPlCode(), $rule->getCostCode()),
                );
            }

            foreach ($this->saleRulesFor($marketplace) as $rule) {
                self::assertContains(
                    $rule->getPlCode(),
                    self::ALLOWED_PL_CODES,
                    sprintf('Неизвестная статья ОПиУ "%s" у правила "%s".', $rule->getPlCode(), $rule->getAmountSource()->value),
                );
            }
        }
    }

    /**
     * Ключевой инвариант: возвраты приходят из источников положительными числами,
     * а листья «Возвраты …(−)» и «Себестоимость возвратов» суммируются родителем
     * напрямую. is_negative=false у возврата прибавляет его к выручке вместо
     * вычитания — ошибка удваивает расхождение и не видна в интерфейсе.
     */
    public function testEveryReturnRuleInvertsSign(): void
    {
        foreach (MarketplaceType::cases() as $marketplace) {
            foreach ($this->saleRulesFor($marketplace) as $rule) {
                $expected = 'return' === $rule->getOperationType();

                self::assertSame(
                    $expected,
                    $rule->isNegative(),
                    sprintf(
                        'Правило "%s" (%s) должно иметь is_negative=%s.',
                        $rule->getAmountSource()->value,
                        $marketplace->value,
                        $expected ? 'true' : 'false',
                    ),
                );
            }
        }
    }

    /**
     * Каждая из шести строк ОПиУ по продажам и возвратам должна быть покрыта
     * ровно одним правилом. Пропуск — молча отсутствующая строка; дубль — двойной
     * счёт (например, sale_revenue и sale_realization оба дали бы выручку с СПП).
     */
    public function testSaleMappingsCoverEachPlLineExactlyOnce(): void
    {
        $expected = [
            'REV_NOT_SPP',
            'REV_SPP_SALES',
            'COGS_PRODUCT_REV',
            'REV_RETURNS',
            'REV_SPP_RETURNS',
            'COGS_PRODUCT_RET',
        ];

        foreach ([MarketplaceType::OZON, MarketplaceType::WILDBERRIES] as $marketplace) {
            $plCodes = array_map(
                static fn (DefaultSaleMappingRule $rule): string => $rule->getPlCode(),
                $this->saleRulesFor($marketplace),
            );

            sort($plCodes);
            $expectedSorted = $expected;
            sort($expectedSorted);

            self::assertSame(
                $expectedSorted,
                $plCodes,
                sprintf('Набор строк ОПиУ для "%s" не совпадает с ожидаемым.', $marketplace->value),
            );
        }
    }

    /** @return list<DefaultCostMappingRule> */
    private function rulesFor(MarketplaceType $marketplace): array
    {
        $provider = new DefaultCostMappingYamlProvider(
            \dirname(__DIR__, 4).'/config/marketplace/default_cost_mapping.yaml',
        );

        return $provider->getForMarketplace($marketplace)->getRules();
    }

    /** @return list<DefaultSaleMappingRule> */
    private function saleRulesFor(MarketplaceType $marketplace): array
    {
        $provider = new DefaultSaleMappingYamlProvider(
            \dirname(__DIR__, 4).'/config/marketplace/default_sale_mapping.yaml',
        );

        return $provider->getForMarketplace($marketplace)->getRules();
    }
}
