<?php

declare(strict_types=1);

namespace App\Settlement\Enum;

enum SettlementDocumentKind: string
{
    case OPENING = 'opening';
    case OBLIGATION = 'obligation';
    case PAYMENT = 'payment';
    case CORRECTION = 'correction';
    case ALLOCATION = 'allocation';
    case CLASSIFICATION = 'classification';
    case ALLOCATION_REVERSAL = 'allocation_reversal';
    case DOCUMENT_REVERSAL = 'document_reversal';

    public function label(): string
    {
        return match ($this) {
            self::OPENING => 'Начальный остаток',
            self::OBLIGATION => 'Основание долга',
            self::PAYMENT => 'Оплата',
            self::CORRECTION => 'Корректировка',
            self::ALLOCATION => 'Погашение',
            self::CLASSIFICATION => 'Классификация оплаты',
            self::ALLOCATION_REVERSAL => 'Отмена погашения',
            self::DOCUMENT_REVERSAL => 'Отмена документа',
        };
    }
}
