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
     * Разрешение идёт ТОЛЬКО по имени и намеренно не использует карту
     * `typeIds` внутри каталога: она расходится со справочником Ozon. На
     * выгрузке 2026-06 из 18 встреченных услуг 15 разбираются по имени, а все
     * три случая, где сработала бы карта `type_id`, дают неверную категорию:
     *
     *   29 LastMileCourier             -> карта говорит «Логистика» (Logistic это 32)
     *   45 PickUpPointReturnAcceptance -> карта говорит «Доставка до места выдачи»
     *   77 SupplyInbound               -> карта говорит «Обработка товара в грузоместе»
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
