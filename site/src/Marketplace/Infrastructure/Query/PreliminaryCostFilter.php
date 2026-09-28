<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Какие затраты оперативное закрытие не берёт в ОПиУ.
 *
 * Один фильтр на три запроса, которые обязаны зеркалить друг друга:
 * UnprocessedCostsQuery::execute(), ::getControlSum() и
 * MarkProcessedQuery::markCosts(). Расхождение между ними — дельта контрольной
 * суммы и RuntimeException в CloseMonthStageAction.
 *
 * Ozon: только распознанные коды (OzonCostCategory::recognizedCodes()) — то же
 * определение, по которому preflight блокирует финальное закрытие; раньше
 * исключалась одна легаси-корзина, а `ozon_unknown_*` из by-day попадали в
 * оперативный ОПиУ. Остальные маркетплейсы — прежнее правило.
 *
 * Условие ссылается на алиас `mcc` таблицы marketplace_cost_categories.
 */
final class PreliminaryCostFilter
{
    /**
     * Возвращает SQL-фрагмент, его параметры и типы параметров.
     *
     * @return array{0: string, 1: array<string, list<string>>, 2: array<string, int>}
     */
    public static function build(string $marketplace, bool $preliminary): array
    {
        if (!$preliminary) {
            return ['', [], []];
        }

        if (MarketplaceType::OZON->value !== $marketplace) {
            return ["AND mcc.code != 'ozon_other_service'", [], []];
        }

        return [
            'AND mcc.code IN (:recognizedCostCodes)',
            ['recognizedCostCodes' => OzonCostCategory::recognizedCodes()],
            ['recognizedCostCodes' => ArrayParameterType::STRING],
        ];
    }
}
