<?php

declare(strict_types=1);

namespace App\Ingestion\Enum;

enum TransactionType: string
{
    case SALE = 'sale';
    case REFUND = 'refund';
    case COMMISSION = 'commission';
    case LOGISTICS = 'logistics';
    case STORAGE = 'storage';
    case LAST_MILE = 'last_mile';
    case ACCEPTANCE = 'acceptance';
    case ADVERTISING = 'advertising';
    case PENALTY = 'penalty';
    case BONUS = 'bonus';
    case ACQUIRING = 'acquiring';
    case ADJUSTMENT = 'adjustment';
    case PAYOUT = 'payout';
    case DEPOSIT = 'deposit';
    case TRANSFER = 'transfer';
    case TAX = 'tax';
    case FEE = 'fee';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SALE => 'Продажа',
            self::REFUND => 'Возврат',
            self::COMMISSION => 'Комиссия',
            self::LOGISTICS => 'Логистика',
            self::STORAGE => 'Хранение',
            self::LAST_MILE => 'Последняя миля',
            self::ACCEPTANCE => 'Приёмка',
            self::ADVERTISING => 'Реклама',
            self::PENALTY => 'Штраф',
            self::BONUS => 'Бонус',
            self::ACQUIRING => 'Эквайринг',
            self::ADJUSTMENT => 'Корректировка',
            self::PAYOUT => 'Выплата',
            self::DEPOSIT => 'Поступление',
            self::TRANSFER => 'Перевод',
            self::TAX => 'Налог',
            self::FEE => 'Сбор',
            self::OTHER => 'Прочее',
        };
    }
}
