<?php

namespace App\Marketplace\Enum;

/**
 * StagingRecordType - типы записей из маркетплейсов
 */
enum StagingRecordType: string
{
    /**
     * Продажа
     */
    case SALE = 'sale';

    /**
     * Возврат
     */
    case RETURN = 'return';

    /**
     * Расход/комиссия
     */
    case COST = 'cost';

    /**
     * Сторно (отмена операции)
     */
    case STORNO = 'storno';

    /**
     * Получить человекочитаемое название
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SALE => 'Продажа',
            self::RETURN => 'Возврат',
            self::COST => 'Расход',
            self::STORNO => 'Сторно',
        };
    }

    /**
     * Получить иконку для UI
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::SALE => '💰',
            self::RETURN => '↩️',
            self::COST => '💸',
            self::STORNO => '❌',
        };
    }

    /**
     * Получить CSS класс для badge
     */
    public function getBadgeClass(): string
    {
        return match ($this) {
            self::SALE => 'badge-success',
            self::RETURN => 'badge-warning',
            self::COST => 'badge-info',
            self::STORNO => 'badge-danger',
        };
    }

    /**
     * Получить название класса финальной сущности
     */
    public function getFinalEntityClass(): string
    {
        return match ($this) {
            self::SALE => 'MarketplaceSale',
            self::RETURN => 'MarketplaceReturn',
            self::COST => 'MarketplaceCost',
            self::STORNO => 'MarketplaceSale', // Сторно тоже как продажа, но с отрицательной суммой
        };
    }

    /**
     * Является ли тип положительным для выручки
     */
    public function isPositive(): bool
    {
        return $this === self::SALE;
    }

    /**
     * Является ли тип отрицательным для выручки
     */
    public function isNegative(): bool
    {
        return in_array($this, [self::RETURN, self::STORNO], true);
    }
}
