<?php

declare(strict_types=1);

namespace App\Ingestion\Facade;

use App\Ingestion\Application\DTO\OzonAccrualCategoryView;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategory;
use App\Ingestion\Enum\TransactionType;

/**
 * Единственная точка, через которую другие модули разбирают услуги Ozon
 * из /v1/finance/accrual/by-day в категории затрат.
 *
 * Read-only и без зависимостей: справочник категорий — статический каталог
 * внутри Ingestion. Фасад существует, чтобы Marketplace не заводил вторую его
 * копию: одно доменное понятие в двух местах со временем разойдётся и даст
 * взаимоисключающие показания в ОПиУ (`docs/workflow/health-gates.md`).
 */
final readonly class OzonAccrualCategoryFacade
{
    /**
     * Разобрать услугу по имени из справочника Ozon.
     *
     * Разрешение идёт ТОЛЬКО по имени: номер `type_id` статью не выбирает. Прежняя
     * карта `typeIds` каталога расходилась со справочником Ozon (29 — это
     * LastMileCourier, а не Logistic) и удалена; алиасы сверены с `description`
     * из `/v1/finance/accrual/types`.
     *
     * Правдоподобная чужая категория хуже честного «не разобрано»: первая тихо
     * искажает ОПиУ, вторая попадает в очередь на разбор. Поэтому неразобранная
     * услуга деградирует в видимую синтетическую категорию, а не в NULL и не в
     * «прочее».
     */
    public function resolveByServiceType(?string $typeId, ?string $typeName): OzonAccrualCategoryView
    {
        $category = OzonAccrualCategory::findByOzonName($typeName)
            ?? OzonAccrualCategory::unknown($typeId, $typeName, TransactionType::FEE);

        return new OzonAccrualCategoryView(
            code: $category->code,
            label: $category->label,
            group: $category->group,
            sortOrder: $category->sortOrder,
            known: $category->known,
            typeId: $typeId,
            typeName: $typeName,
        );
    }
}
