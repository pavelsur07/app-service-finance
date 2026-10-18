<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Стартовый закрытый список групп Ozon и правила подсказки группы
 * (`ingest_category_groups`, `ingest_category_group_rules`).
 *
 * UUID фиксированы (v5 от `ingest_category_groups:ozon:<code>` и
 * `ingest_category_group_rules:ozon:<priority>` — детерминированы, в отличие от
 * v7 для строк, создаваемых приложением): миграция Version20261018160000 вставляет те же значения,
 * а тестовый `DbReset` восстанавливает их отсюда после TRUNCATE. Дальше правила
 * живут в БД и правятся данными — этот класс не источник истины для прода.
 *
 * Правила выверены по группам отчёта кабинета Ozon «Детализация начислений»
 * (docs/tasks/ozon-accrual-types-dictionary/stages/stage-0.md).
 */
final class OzonCategoryGroupSeed
{
    public const UNCLASSIFIED_CODE = 'unclassified';

    /**
     * @return array<string, array{code: string, label: string, ozonLabel: ?string, sortOrder: int}> id => группа
     */
    public static function groups(): array
    {
        return [
            '82fbebca-22d5-5fd3-a287-0009e4efa796' => ['code' => 'sales', 'label' => 'Продажи', 'ozonLabel' => null, 'sortOrder' => 100],
            'e0159dad-d870-519e-a079-913c9e3949b9' => ['code' => 'returns', 'label' => 'Возвраты', 'ozonLabel' => null, 'sortOrder' => 200],
            '79b90d0a-2b98-5434-9021-dd34850fcde2' => ['code' => 'commission', 'label' => 'Вознаграждение Ozon', 'ozonLabel' => null, 'sortOrder' => 300],
            'db7285bd-0f4a-5b8a-b717-4abecc0fcb36' => ['code' => 'delivery', 'label' => 'Услуги доставки', 'ozonLabel' => null, 'sortOrder' => 400],
            '4d80ff52-6e86-5f65-836a-7225ef6fda08' => ['code' => 'partners', 'label' => 'Услуги партнёров', 'ozonLabel' => null, 'sortOrder' => 500],
            '5ca1538b-308e-5433-b33a-60f0234772d3' => ['code' => 'fbo', 'label' => 'Услуги FBO', 'ozonLabel' => null, 'sortOrder' => 600],
            'feb2e593-5f94-5348-904b-a3a51b5d5a65' => ['code' => 'promotion', 'label' => 'Продвижение и реклама', 'ozonLabel' => null, 'sortOrder' => 700],
            '8fb95d44-8de5-5057-b591-fdee85ba87a3' => ['code' => 'compensation', 'label' => 'Компенсации', 'ozonLabel' => 'Компенсации и декомпенсации', 'sortOrder' => 800],
            '4a9b75d2-fa3b-590b-99c1-f9aec6fba1f0' => ['code' => 'other', 'label' => 'Другие услуги и штрафы', 'ozonLabel' => null, 'sortOrder' => 900],
            '4e0c9b4d-4620-5f1e-a5d5-02763322ce86' => ['code' => self::UNCLASSIFIED_CODE, 'label' => 'Требует классификации', 'ozonLabel' => null, 'sortOrder' => 9000],
        ];
    }

    /**
     * @return array<string, array{priority: int, groupCode: string, confidence: string, pattern: string, note: ?string}> id => правило
     */
    public static function rules(): array
    {
        return [
            'cd80086c-ffd8-52b8-b10c-f9e2381db2a2' => ['priority' => 10, 'groupCode' => 'compensation', 'confidence' => 'high', 'pattern' => 'компенсац|compensation', 'note' => 'Компенсации и декомпенсации'],
            '5eab157c-b721-5bfb-9c57-9258a64b3c28' => ['priority' => 20, 'groupCode' => 'partners', 'confidence' => 'high', 'pattern' => 'партн[её]р|agent\b|эквайринг|acquiring|звёздн|звездн|stars|страхован|insurance', 'note' => 'Ozon: страхование и «Звёздные товары» — услуги партнёров'],
            '5fdbaab2-09de-57fc-af64-d269cbcbede7' => ['priority' => 30, 'groupCode' => 'promotion', 'confidence' => 'high', 'pattern' => 'реклам|продвиж|отзыв|review|promotion|marketing|оплата за клик|payperclick|пуш|push|brand|бренд|подписка premium|premiumsubscription|баннер|трафарет', 'note' => 'Ozon: подписка Premium — продвижение'],
            'c94f3f16-f8ae-5794-a3db-64db56aa4619' => ['priority' => 40, 'groupCode' => 'other', 'confidence' => 'high', 'pattern' => 'штраф|defect|fine|благотвор|charity|утилиз|disposal|досрочн|earlypayment|рассрочк|installment|маркировк|label|корректир|correction|кешб|кэшб|cashback|rfbs|материал|packagecost|нерекомендованн|слот', 'note' => null],
            '9174c2c9-b42e-526b-a49c-ac003b928fbe' => ['priority' => 50, 'groupCode' => 'fbo', 'confidence' => 'high', 'pattern' => 'склад|размещ|placement|supply|при[её]мк|crossdock|кросс-док|вывоз|sellerreturns|itempacking|грузомест', 'note' => null],
            '208f6ff2-7ce4-5f3b-841c-53498fb4eb6f' => ['priority' => 60, 'groupCode' => 'delivery', 'confidence' => 'high', 'pattern' => 'логистик|logistic|доставк|delivery|drop-off|магистрал|backward|shipment|курьер|courier', 'note' => null],
            'c1de3c26-aade-53c7-b617-1dfd65d9b476' => ['priority' => 90, 'groupCode' => 'promotion', 'confidence' => 'medium', 'pattern' => 'premium|премиум|analytics|аналитик', 'note' => 'Слабый сигнал: только подсказка'],
        ];
    }
}
