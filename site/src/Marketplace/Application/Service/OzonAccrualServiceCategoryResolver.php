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
 * Соответствие проверено дважды. Во-первых, полем `description` из самого
 * справочника `/v1/finance/accrual/types` — там русское описание услуги от Ozon,
 * и у большинства оно совпадает с именем нашей категории почти дословно
 * («ItemPacking» — «Дополнительная упаковка на складе Ozon»). Во-вторых, сверкой
 * с легаси-путём на тех же кабинетах за 01–07.09:
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
     * Услуги, у которых направление кодируется КАТЕГОРИЕЙ, а не только
     * `operation_type`.
     *
     * Так это ведёт легаси-путь с января (`OzonCostsRawProcessor`): компенсация
     * от Ozon и списание с продавца лежат в разных категориях, и в истории у них
     * 66 и 51 строка соответственно. Если by-day сложит обе в одну, отчёт по
     * категориям разорвётся на сентябре: до него направление в имени категории,
     * после — только в знаке.
     *
     * @var array<string, array{positive: string, negative: string}>
     */
    private const SIGN_SPLIT_SERVICES = [
        'Compensation' => ['positive' => 'ozon_compensation', 'negative' => 'ozon_decompensation'],
    ];

    /**
     * Резолвер намеренно ничего не логирует: его зовут на каждую строку услуги,
     * а одно массовое начисление даёт тысячи строк за документ. Неразобранные
     * услуги собирает и печатает одним предупреждением вызывающий.
     *
     * @param float $rawAmount сумма как её прислал Ozon, со знаком: у части
     *                         услуг направление выбирает категорию
     *
     * @return array{code: string, name: string, known: bool}
     */
    public function resolve(?string $typeId, ?string $typeName, float $rawAmount): array
    {
        $category = null !== $typeName && '' !== $typeName
            ? OzonCostCategory::findByAccrualTypeName($typeName)
            : null;

        // Знак исходной суммы выбирает категорию, а не только вид операции.
        if (null !== $typeName && isset(self::SIGN_SPLIT_SERVICES[$typeName])) {
            $split = self::SIGN_SPLIT_SERVICES[$typeName];
            $category = OzonCostCategory::findByCode($rawAmount >= 0 ? $split['positive'] : $split['negative']);
        }

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
