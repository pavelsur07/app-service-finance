<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Domain\OzonCostCategory;

/**
 * Разбирает услугу из by-day в категорию затрат Маркетплейса.
 *
 * Почему не фасад Ingestion, который стоял здесь раньше. Его каталог ведёт
 * собственный словарь кодов, и с кодами, на которых построен маппинг ОПиУ у
 * компаний, он расходится: из 25 кодов, пришедших за 08–09.09.2026, до ОПиУ
 * доходили пять. Одна и та же услуга называлась `ozon_logistics` в разборе и
 * `ozon_logistic_direct` в маппинге — 189 164 рубля за два дня мимо отчёта.
 *
 * Соответствие выведено сверкой с легаси-путём на тех же кабинетах за 01–07.09:
 * суточный темп строк совпадает попарно (Logistic 1074 против 975 в день,
 * LastMileCourier 954 против 860, ReturnFlowLogistic 138 против 141 и так
 * далее). Сами имена живут в `OzonCostCategory` — там же, где имена снятого v3,
 * но в отдельном поле: складывать два поколения в один индекс значит однажды
 * разложить услугу не в ту категорию.
 *
 * Неизвестная услуга не теряется и не притворяется известной: она получает
 * собственный видимый код `ozon_unknown_<type_id>` и признак `known: false`.
 * Затрата попадает в справочник, её видно в очереди на разбор, и она ждёт
 * правила в `default_cost_mapping.yaml` — а не исчезает молча.
 */
final readonly class OzonAccrualServiceCategoryResolver
{
    /**
     * Резолвер намеренно ничего не логирует: его зовут на каждую строку услуги,
     * а одно массовое начисление даёт тысячи строк за документ. Неразобранные
     * услуги собирает и печатает одним предупреждением вызывающий.
     *
     * @return array{code: string, name: string, known: bool}
     */
    public function resolve(?string $typeId, ?string $typeName): array
    {
        $category = null !== $typeName && '' !== $typeName
            ? OzonCostCategory::findByAccrualTypeName($typeName)
            : null;

        if (null !== $category) {
            return ['code' => $category->code, 'name' => $category->name, 'known' => true];
        }

        return [
            'code' => sprintf('ozon_unknown_%s', $typeId ?? 'unknown'),
            'name' => null !== $typeName && '' !== $typeName
                ? sprintf('Неразобранная услуга Ozon: %s', $typeName)
                : 'Неразобранная услуга Ozon',
            'known' => false,
        ];
    }
}
