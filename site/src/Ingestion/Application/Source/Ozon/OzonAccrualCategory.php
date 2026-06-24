<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Enum\TransactionType;

final readonly class OzonAccrualCategory
{
    private const UNKNOWN_GROUP = 'Неизвестные категории Ozon';

    /**
     * @param list<string> $typeIds
     * @param list<string> $aliases
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $group,
        public TransactionType $transactionType,
        public int $sortOrder,
        public ?string $parentLabel = null,
        public array $typeIds = [],
        public array $aliases = [],
        public bool $known = true,
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
            new self('ozon_revenue', 'Выручка', 'Продажи', TransactionType::SALE, 100),
            new self('ozon_discount_points', 'Баллы за скидки', 'Продажи', TransactionType::BONUS, 110),
            new self('ozon_partner_programs', 'Программы партнёров', 'Продажи', TransactionType::BONUS, 120),

            new self('ozon_revenue_refund', 'Возврат выручки', 'Возвраты', TransactionType::REFUND, 200),
            new self('ozon_discount_points_refund', 'Баллы за скидки (возврат)', 'Возвраты', TransactionType::BONUS, 210),
            new self('ozon_partner_programs_refund', 'Программы партнёров (возврат)', 'Возвраты', TransactionType::BONUS, 220),

            new self('ozon_sale_commission', 'Вознаграждение за продажу', 'Вознаграждение Ozon', TransactionType::COMMISSION, 300),
            new self('ozon_commission_refund', 'Возврат вознаграждения', 'Вознаграждение Ozon', TransactionType::COMMISSION, 310),

            new self('ozon_logistics', 'Логистика', 'Услуги доставки', TransactionType::LOGISTICS, 400, typeIds: ['29'], aliases: ['Логистика Ozon']),
            new self('ozon_reverse_logistics', 'Обратная логистика', 'Услуги доставки', TransactionType::LOGISTICS, 410, aliases: ['Обратная логистика Ozon']),
            new self('ozon_delivery_to_pickup_ozon', 'Доставка до места выдачи силами Ozon', 'Услуги доставки', TransactionType::LAST_MILE, 420, typeIds: ['45'], aliases: ['Доставка до ПВЗ силами Ozon']),

            new self('ozon_partner_return_processing', 'Обработка возвратов, отмен и невыкупов партнёрами', 'Услуги партнёров', TransactionType::FEE, 500, aliases: ['Обработка возвратов, отмен и невыкупов партнерами']),
            new self('ozon_acquiring', 'Эквайринг', 'Услуги партнёров', TransactionType::ACQUIRING, 510, aliases: ['Эквайринг Ozon']),
            new self('ozon_partner_packaging', 'Упаковка товара партнёрами', 'Услуги партнёров', TransactionType::FEE, 520, aliases: ['Упаковка товара партнерами']),
            new self('ozon_delivery_to_pickup_partners', 'Доставка до места выдачи партнёрами', 'Услуги партнёров', TransactionType::LAST_MILE, 530, aliases: ['Доставка до места выдачи партнерами']),
            new self('ozon_temporary_partner_storage', 'Временное размещение товара партнёрами', 'Услуги партнёров', TransactionType::STORAGE, 540, aliases: ['Временное размещение товара партнерами']),

            new self('ozon_cross_docking', 'Кросс-докинг', 'Услуги FBO', TransactionType::LOGISTICS, 600, parentLabel: 'Доставка до склада', aliases: ['Кросс-докинг Ozon']),
            new self('ozon_warehouse_export', 'Вывоз товара со склада силами Ozon', 'Услуги FBO', TransactionType::STORAGE, 700, parentLabel: 'Складские услуги'),
            new self('ozon_warehouse_placement', 'Размещение товаров на складах', 'Услуги FBO', TransactionType::STORAGE, 710, parentLabel: 'Складские услуги'),
            new self('ozon_warehouse_export_preparation', 'Подготовка товара к вывозу', 'Услуги FBO', TransactionType::STORAGE, 720, parentLabel: 'Складские услуги'),
            new self('ozon_incomplete_supply_booking', 'Бронирование места и персонала для поставки с неполным составом', 'Услуги FBO', TransactionType::ACCEPTANCE, 800, parentLabel: 'Услуги приёмки', aliases: ['Бронирование места и персонала для поставки с неполным составом Ozon']),
            new self('ozon_identified_surplus_processing', 'Обработка опознанных излишков в составе грузоместа', 'Услуги FBO', TransactionType::ACCEPTANCE, 810, parentLabel: 'Услуги приёмки'),
            new self('ozon_cargo_place_item_processing', 'Обработка товара в составе грузоместа', 'Услуги FBO', TransactionType::ACCEPTANCE, 820, parentLabel: 'Услуги приёмки', typeIds: ['77']),

            new self('ozon_cpc', 'Оплата за клик', 'Продвижение и реклама', TransactionType::ADVERTISING, 900, aliases: ['Оплата за клик Ozon']),
            new self('ozon_accelerated_reviews', 'Ускоренный сбор отзывов', 'Продвижение и реклама', TransactionType::ADVERTISING, 910, aliases: ['Приобретение отзывов Ozon', 'Баллы за отзывы']),

            new self('ozon_early_payout', 'Досрочная выплата', 'Другие услуги и штрафы', TransactionType::FEE, 1000),
            new self('ozon_disposal', 'Утилизация товара', 'Другие услуги и штрафы', TransactionType::FEE, 1010),
            new self('ozon_packaging_materials', 'Обеспечение материалами для упаковки товара', 'Другие услуги и штрафы', TransactionType::FEE, 1020),
            new self('ozon_other_services', 'Другие услуги', 'Другие услуги и штрафы', TransactionType::FEE, 1090),
        ];

        return $cache;
    }

    public static function findByCode(string $code): ?self
    {
        return self::byCode()[$code] ?? null;
    }

    public static function findByTypeId(?string $typeId): ?self
    {
        $typeId = self::normalizeTypeId($typeId);
        if (null === $typeId) {
            return null;
        }

        return self::byTypeId()[$typeId] ?? null;
    }

    public static function findByOzonName(?string $name): ?self
    {
        $normalized = self::normalizeName($name);
        if (null === $normalized) {
            return null;
        }

        return self::byAlias()[$normalized] ?? null;
    }

    public static function forTypedFee(?string $typeId, ?string $typeName, TransactionType $fallbackType): self
    {
        return self::findByTypeId($typeId)
            ?? self::findByOzonName($typeName)
            ?? self::unknown($typeId, $typeName, $fallbackType);
    }

    public static function forField(string $field, int $signedAmountMinor): ?self
    {
        $field = self::normalizeField($field);
        $isRefund = $signedAmountMinor < 0;

        return match ($field) {
            'sale_amount', 'seller_price' => self::findByCode($isRefund ? 'ozon_revenue_refund' : 'ozon_revenue'),
            'bonus' => self::findByCode($isRefund ? 'ozon_discount_points_refund' : 'ozon_discount_points'),
            'coinvestment', 'co_investment', 'partner_program', 'partner_programs', 'partner_reward', 'partner_bonus' => self::findByCode($isRefund ? 'ozon_partner_programs_refund' : 'ozon_partner_programs'),
            'commission', 'sale_commission' => self::findByCode($isRefund ? 'ozon_sale_commission' : 'ozon_commission_refund'),
            default => null,
        };
    }

    public static function unknown(?string $typeId, ?string $typeName, TransactionType $fallbackType): self
    {
        $typeId = self::normalizeTypeId($typeId);
        $suffix = null !== $typeId ? $typeId : substr(hash('sha256', trim((string) $typeName)), 0, 8);
        $label = null !== $typeName && '' !== trim($typeName)
            ? sprintf('Неизвестная категория Ozon: %s', trim($typeName))
            : sprintf('Неизвестный type_id Ozon: %s', $typeId ?? 'unknown');

        return new self(
            code: sprintf('ozon_unknown_%s', preg_replace('/[^a-zA-Z0-9_]+/', '_', $suffix) ?: 'unknown'),
            label: $label,
            group: self::UNKNOWN_GROUP,
            transactionType: $fallbackType,
            sortOrder: 9000,
            typeIds: null !== $typeId ? [$typeId] : [],
            aliases: null !== $typeName ? [$typeName] : [],
            known: false,
        );
    }

    /**
     * @return array<string, self>
     */
    public static function byCode(): array
    {
        /** @var array<string, self>|null $map */
        static $map = null;

        if (null !== $map) {
            return $map;
        }

        $map = [];
        foreach (self::all() as $category) {
            $map[$category->code] = $category;
        }

        return $map;
    }

    /**
     * @return array<string, self>
     */
    private static function byTypeId(): array
    {
        /** @var array<string, self>|null $map */
        static $map = null;

        if (null !== $map) {
            return $map;
        }

        $map = [];
        foreach (self::all() as $category) {
            foreach ($category->typeIds as $typeId) {
                $normalized = self::normalizeTypeId($typeId);
                if (null !== $normalized) {
                    $map[$normalized] = $category;
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, self>
     */
    private static function byAlias(): array
    {
        /** @var array<string, self>|null $map */
        static $map = null;

        if (null !== $map) {
            return $map;
        }

        $map = [];
        foreach (self::all() as $category) {
            foreach (array_merge([$category->label], $category->aliases) as $alias) {
                $normalized = self::normalizeName($alias);
                if (null !== $normalized) {
                    $map[$normalized] = $category;
                }
            }
        }

        return $map;
    }

    private static function normalizeTypeId(?string $typeId): ?string
    {
        $typeId = trim((string) $typeId);

        return '' !== $typeId && 'unknown' !== strtolower($typeId) ? $typeId : null;
    }

    private static function normalizeField(string $field): string
    {
        return strtolower(trim($field));
    }

    private static function normalizeName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ('' === $name) {
            return null;
        }

        $name = mb_strtolower($name);
        $name = str_replace('ё', 'е', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }
}
