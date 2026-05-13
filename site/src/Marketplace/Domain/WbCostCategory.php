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
    ) {}

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        /** @var list<self>|null $cache */
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = [
            new self('commission', 'Комиссия маркетплейса', 'Вознаграждение', 'Вознаграждение', 'commission'),
            new self('logistics_delivery', 'Логистика до покупателя', 'Услуги доставки и FBO', 'Услуги доставки', 'logistics'),
            new self('logistics_return', 'Логистика возврат', 'Услуги доставки и FBO', 'Услуги доставки', 'logistics'),
            new self('warehouse_logistics', 'Логистика складские операции', 'Услуги доставки и FBO', 'Услуги FBO', 'logistics'),
            new self('storage', 'Хранение WB', 'Услуги доставки и FBO', 'Услуги FBO', 'other'),
            new self('pvz_processing', 'Логистика обработка на ПВЗ', 'Услуги партнёров', 'Услуги партнёров', 'other'),
            new self('acquiring', 'Эквайринг', 'Услуги партнёров', 'Услуги партнёров', 'other'),
            new self('penalty', 'Штраф WB', 'Другие услуги и штрафы', 'Другие услуги и штрафы', 'other'),
            new self('wb_okazanie_uslug_wb_prodvizhenie', 'Оказание услуг «WB Продвижение»', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_spisanie_za_otzyv', 'Списание за отзыв', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_vozvrat_neispolzovannogo_ostatka_avansa_za_uslu', 'Возврат неиспользованного остатка аванса за услугу "Баллы за отзывы"', 'Продвижение и реклама', 'Продвижение и реклама', 'other'),
            new self('wb_loyalty_discount_compensation', 'Компенсация скидки по программе лояльности WB', 'Другие услуги и штрафы', 'Компенсации и декомпенсации', 'other'),
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

        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        foreach (self::all() as $category) {
            $cache[$category->code] = $category;
        }

        return $cache;
    }
}
