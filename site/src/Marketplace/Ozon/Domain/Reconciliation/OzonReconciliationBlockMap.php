<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Domain\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Ozon\Domain\OzonCostCategory;

/**
 * Код категории затрат → блок сверки.
 *
 * Блок выводится из группы xlsx в `OzonCostCategory` — своего справочника нет, чтобы
 * новый код Ozon, добавленный в каталог, сразу попадал в сверку. Код вне распознанных
 * (`ozon_unknown_<type_id>`, легаси-корзина `ozon_other_service`) — нераспознанный.
 */
final class OzonReconciliationBlockMap
{
    private const GROUP_TO_BLOCK = [
        'Вознаграждение Ozon' => OzonReconciliationBlock::COMMISSION,
        'Услуги доставки' => OzonReconciliationBlock::LOGISTICS,
        'Услуги FBO' => OzonReconciliationBlock::FBO_SERVICES,
        'Услуги партнёров' => OzonReconciliationBlock::PARTNER_SERVICES,
        'Продвижение и реклама' => OzonReconciliationBlock::ADVERTISING,
        'Компенсации и декомпенсации' => OzonReconciliationBlock::COMPENSATIONS,
        'Другие услуги и штрафы' => OzonReconciliationBlock::OTHER_FEES,
    ];

    public static function forCategoryCode(string $code): OzonReconciliationBlock
    {
        if (!in_array($code, OzonCostCategory::recognizedCodes(), true)) {
            return OzonReconciliationBlock::UNRECOGNIZED;
        }

        $category = OzonCostCategory::findByCode($code);

        return null === $category
            ? OzonReconciliationBlock::UNRECOGNIZED
            : (self::GROUP_TO_BLOCK[$category->xlsxGroup] ?? OzonReconciliationBlock::UNRECOGNIZED);
    }
}
