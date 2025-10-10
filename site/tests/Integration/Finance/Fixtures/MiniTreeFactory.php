<?php
declare(strict_types=1);

namespace Tests\Integration\Finance\Fixtures;

use App\Entity\Company;
use App\Entity\PLCategory;
use App\Enum\PLCategoryType;
use App\Enum\PLValueFormat;
use Ramsey\Uuid\Uuid;

final class MiniTreeFactory
{
    /**
     * Создает in-memory набор PLCategory:
     * REV_TOTAL (SUBTOTAL)
     *   REV_WB (LEAF_INPUT)
     * VAR_COSTS_TOTAL (SUBTOTAL)
     *   COGS (LEAF_INPUT)
     * MARGIN (KPI) = REV_TOTAL - VAR_COSTS_TOTAL
     * MARGIN_PCT (KPI,PERCENT) = SAFE_DIV(MARGIN, REV_TOTAL)
     *
     * @return PLCategory[]
     */
    public static function build(Company $company): array
    {
        $revTotal = self::cat($company, 'REV_TOTAL', 'Выручка', PLCategoryType::SUBTOTAL, PLValueFormat::MONEY, 1, null);
        $revWB    = self::cat($company, 'REV_WB', 'Wildberries', PLCategoryType::LEAF_INPUT, PLValueFormat::MONEY, 2, $revTotal);
        $varTot   = self::cat($company, 'VAR_COSTS_TOTAL', 'Переменные расходы', PLCategoryType::SUBTOTAL, PLValueFormat::MONEY, 3, null);
        $cogs     = self::cat($company, 'COGS', 'Себестоимость', PLCategoryType::LEAF_INPUT, PLValueFormat::MONEY, 4, $varTot);
        $margin   = self::cat($company, 'MARGIN', 'Маржинальная прибыль', PLCategoryType::KPI, PLValueFormat::MONEY, 5, null, 'REV_TOTAL - VAR_COSTS_TOTAL');
        $marginP  = self::cat($company, 'MARGIN_PCT', 'Маржинальность', PLCategoryType::KPI, PLValueFormat::PERCENT, 6, null, 'SAFE_DIV(MARGIN, REV_TOTAL)');

        return [$revTotal, $revWB, $varTot, $cogs, $margin, $marginP];
    }

    private static function cat(Company $company, ?string $code, string $name, PLCategoryType $type, PLValueFormat $fmt, int $sort, ?PLCategory $parent, ?string $formula = null): PLCategory
    {
        $c = new PLCategory(Uuid::uuid4()->toString(), $company);
        $c->setName($name);
        $c->setCode($code);
        $c->setType($type);
        $c->setFormat($fmt);
        $c->setSortOrder($sort);
        if ($parent) $c->setParent($parent);
        if ($formula) $c->setFormula($formula);
        return $c;
    }
}
