<?php

declare(strict_types=1);

namespace App\Company\Enum;

/**
 * Направление бизнеса компании. Выбирается при создании и определяет стартовые шаблоны
 * онбординга (дерево ОПиУ, структура Баланса).
 */
enum BusinessProfile: string
{
    case MARKETPLACE = 'MARKETPLACE';
    case UNIVERSAL = 'UNIVERSAL';

    /** Основная аудитория продукта; профиль всех компаний, созданных до выбора профиля. */
    public const DEFAULT = self::MARKETPLACE;

    public function label(): string
    {
        return match ($this) {
            self::MARKETPLACE => 'Маркетплейсы и e-com',
            self::UNIVERSAL => 'Базовый',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::MARKETPLACE => 'Продаю на Wildberries, Ozon или в своём интернет-магазине',
            self::UNIVERSAL => 'Другой бизнес: услуги, опт, розница, производство',
        };
    }
}
