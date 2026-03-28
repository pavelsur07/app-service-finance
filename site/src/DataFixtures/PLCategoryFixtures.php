<?php

namespace App\DataFixtures;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Nonstandard\Uuid;

final class PLCategoryFixtures extends Fixture implements DependentFixtureInterface
{
    // ── Корневые секции ──
    public const REF_ROOT_REVENUE = 'pl.root_revenue';
    public const REF_ROOT_COGS = 'pl.root_cogs';
    public const REF_ROOT_MP_COSTS = 'pl.root_mp_costs';
    public const REF_ROOT_OPEX = 'pl.root_opex';

    // ── Выручка ──
    public const REF_SALES_WB = 'pl.sales_wb';
    public const REF_SALES_OZON = 'pl.sales_ozon';
    public const REF_MP_GROSS_REVENUE = 'pl.mp_gross_revenue';
    public const REF_MP_NET_REVENUE = 'pl.mp_net_revenue';
    public const REF_MP_RETURNS = 'pl.mp_returns';

    // ── Себестоимость ──
    public const REF_COGS_MATERIALS = 'pl.cogs_materials';
    public const REF_COGS_PRODUCTION = 'pl.cogs_production';

    // ── Расходы маркетплейса ──
    public const REF_MP_COMMISSION = 'pl.mp_commission';
    public const REF_MP_ACQUIRING = 'pl.mp_acquiring';
    public const REF_MP_LOGISTICS_DELIVERY = 'pl.mp_logistics_delivery';
    public const REF_MP_LOGISTICS_RETURN = 'pl.mp_logistics_return';
    public const REF_MP_STORAGE = 'pl.mp_storage';
    public const REF_MP_PENALTY = 'pl.mp_penalty';
    public const REF_MP_ADVERTISING = 'pl.mp_advertising';
    public const REF_MP_PVZ = 'pl.mp_pvz';
    public const REF_MP_WAREHOUSE = 'pl.mp_warehouse';
    public const REF_MP_PRODUCT_PROCESSING = 'pl.mp_product_processing';
    public const REF_MP_LOYALTY_DISCOUNT = 'pl.mp_loyalty_discount';

    // ── Операционные расходы ──
    public const REF_OPEX_MARKETING = 'pl.opex_marketing';
    public const REF_OPEX_RENT = 'pl.opex_rent';
    public const REF_OPEX_PAYROLL = 'pl.opex_payroll';

    public function load(ObjectManager $manager): void
    {
        /** @var Company $company */
        $company = $this->getReference(AppFixtures::REF_COMPANY_ROMASHKA, Company::class);

        $make = function (
            string $name,
            string $code,
            int $sort,
            ?PLCategory $parent = null,
            PLFlow $flow = PLFlow::NONE,
            PLExpenseType $expenseType = PLExpenseType::OTHER,
            ?string $ref = null,
        ) use ($company, $manager): PLCategory {
            $category = new PLCategory(Uuid::uuid4()->toString(), $company);
            $category->setName($name);
            $category->setCode($code);
            $category->setSortOrder($sort);
            $category->setParent($parent);
            $category->setFlow($flow);
            $category->setExpenseType($expenseType);

            $manager->persist($category);

            if (null !== $ref) {
                $this->addReference($ref, $category);
            }

            return $category;
        };

        // =====================================================================
        // 1. ВЫРУЧКА
        // =====================================================================
        $revenue = $make('Выручка', 'REVENUE', 100, null, PLFlow::NONE, PLExpenseType::OTHER, self::REF_ROOT_REVENUE);

        $make('Выкупы без СПП', 'MP_GROSS_REVENUE', 110, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_MP_GROSS_REVENUE);
        $make('Выручка с СПП', 'MP_NET_REVENUE', 120, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_MP_NET_REVENUE);
        $make('Возвраты (−)', 'MP_RETURNS', 130, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_MP_RETURNS);
        $make('Продажи WB (прочее)', 'SALES_WB', 140, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_SALES_WB);
        $make('Продажи Ozon (прочее)', 'SALES_OZON', 150, $revenue, PLFlow::INCOME, PLExpenseType::OTHER, self::REF_SALES_OZON);

        // =====================================================================
        // 2. СЕБЕСТОИМОСТЬ
        // =====================================================================
        $cogs = $make('Себестоимость', 'COGS', 200, null, PLFlow::NONE, PLExpenseType::OTHER, self::REF_ROOT_COGS);

        $make('Материалы', 'COGS_MATERIALS', 210, $cogs, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_COGS_MATERIALS);
        $make('Производство', 'COGS_PRODUCTION', 220, $cogs, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_COGS_PRODUCTION);

        // =====================================================================
        // 3. РАСХОДЫ МАРКЕТПЛЕЙСА
        // =====================================================================
        $mpCosts = $make('Расходы маркетплейса', 'MP_COSTS', 300, null, PLFlow::NONE, PLExpenseType::OTHER, self::REF_ROOT_MP_COSTS);

        $make('Комиссия МП', 'MP_COMMISSION', 310, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_COMMISSION);
        $make('Эквайринг', 'MP_ACQUIRING', 320, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_ACQUIRING);
        $make('Логистика доставки', 'MP_LOGISTICS_DELIVERY', 330, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_LOGISTICS_DELIVERY);
        $make('Логистика возвратов', 'MP_LOGISTICS_RETURN', 340, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_LOGISTICS_RETURN);
        $make('Хранение', 'MP_STORAGE', 350, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_STORAGE);
        $make('Штрафы и удержания', 'MP_PENALTY', 360, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_PENALTY);
        $make('Реклама МП', 'MP_ADVERTISING', 370, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_ADVERTISING);
        $make('Обработка ПВЗ', 'MP_PVZ', 380, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_PVZ);
        $make('Складские операции', 'MP_WAREHOUSE', 390, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_WAREHOUSE);
        $make('Обработка товара', 'MP_PRODUCT_PROCESSING', 400, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_PRODUCT_PROCESSING);
        $make('Компенсация скидки лояльности', 'MP_LOYALTY_DISCOUNT', 410, $mpCosts, PLFlow::EXPENSE, PLExpenseType::VARIABLE, self::REF_MP_LOYALTY_DISCOUNT);

        // =====================================================================
        // 4. ОПЕРАЦИОННЫЕ РАСХОДЫ
        // =====================================================================
        $opex = $make('Операционные расходы', 'OPEX', 500, null, PLFlow::NONE, PLExpenseType::OTHER, self::REF_ROOT_OPEX);

        $make('Маркетинг', 'OPEX_MARKETING', 510, $opex, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_MARKETING);
        $make('Аренда', 'OPEX_RENT', 520, $opex, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_RENT);
        $make('Зарплата', 'OPEX_PAYROLL', 530, $opex, PLFlow::EXPENSE, PLExpenseType::OPEX, self::REF_OPEX_PAYROLL);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }
}
