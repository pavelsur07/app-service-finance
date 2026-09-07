<?php

declare(strict_types=1);

namespace App\Marketplace\Domain;

final readonly class WbCostCategory
{
    public function __construct(
        public string $code,
        public string $name,
        public string $widgetGroup,
        public string $breakdownGroup,
        public string $unitBucket,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        /** @var list<self>|null $cache */
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $cache = [
            new self('commission', 'Комиссия маркетплейса', 'Вознаграждение', 'Вознаграждение', 'commission'),
            new self('logistics_delivery', 'Логистика до покупателя', 'Услуги доставки и FBO', 'Услуги доставки', 'logistics'),
            new self('logistics_return', 'Логистика возврат', 'Услуги доставки и FBO', 'Услуги доставки', 'logistics'),
            new self('logistics_correction', 'Коррекция логистики', 'Услуги доставки и FBO', 'Услуги доставки', 'logistics'),
            new self('warehouse_logistics', 'Логистика складские операции', 'Услуги доставки и FBO', 'Услуги FBO', 'logistics'),
            new self('storage', 'Хранение WB', 'Услуги доставки и FBO', 'Услуги FBO', 'other'),
            new self('pvz_processing', 'Логистика обработка на ПВЗ', 'Услуги партнёров', 'Услуги партнёров', 'other'),
            new self('acquiring', 'Эквайринг', 'Услуги партнёров', 'Услуги партнёров', 'other'),
            new self('penalty', 'Штраф WB', 'Другие услуги и штрафы', 'Другие услуги и штрафы', 'other'),
            new self('wb_okazanie_uslug_wb_prodvizhenie', 'Оказание услуг «WB Продвижение»', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_spisanie_za_otzyv', 'Списание за отзыв', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_vozvrat_neispolzovannogo_ostatka_avansa_za_uslu', 'Возврат неиспользованного остатка аванса за услугу "Баллы за отзывы"', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_loyalty_discount_compensation', 'Компенсация скидки по программе лояльности WB', 'Другие услуги и штрафы', 'Компенсации и декомпенсации', 'other'),
            new self('wb_dobrovolnaya_vyplata_za_tovary', 'Добровольная выплата за товары', 'Другие услуги и штрафы', 'Компенсации и декомпенсации', 'other'),
            new self('wb_warehouse_disposal', 'Утилизация товара на складе WB', 'Другие услуги и штрафы', 'Другие услуги и штрафы', 'other'),
            new self('product_processing', 'Обработка товара', 'Услуги доставки и FBO', 'Услуги FBO', 'other'),
        ];

        return $cache;
    }

    /**
     * @return array<string, self>
     */
    public static function byCode(): array
    {
        /** @var array<string, self>|null $cache */
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $cache = [];
        foreach (self::all() as $category) {
            $cache[$category->code] = $category;
        }

        return $cache;
    }

    public static function forDeductionName(string $name): ?self
    {
        // Keep this resolver aligned with WbDeductionCategory::resolve();
        // WbCostCategoryTest::knownDeductionReasonProvider guards cross-pipeline parity.
        $normalized = str_replace('ё', 'е', mb_strtolower(trim($name)));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return match (true) {
            str_starts_with($normalized, 'добровольная выплата за товары') => self::byCode()['wb_dobrovolnaya_vyplata_za_tovary'] ?? null,
            str_starts_with($normalized, 'отчет об утилизированном товаре') => self::byCode()['wb_warehouse_disposal'] ?? null,
            default => null,
        };
    }
}
