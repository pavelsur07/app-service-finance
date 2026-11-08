<?php

declare(strict_types=1);

namespace App\Settlement\Enum;

enum SettlementBucket: string
{
    case RECEIVABLE = 'receivable';
    case PAYABLE = 'payable';
    case ADVANCE_GIVEN = 'advance_given';
    case ADVANCE_RECEIVED = 'advance_received';
    case UNALLOCATED_OUT = 'unallocated_out';
    case UNALLOCATED_IN = 'unallocated_in';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVABLE => 'Нам должны',
            self::PAYABLE => 'К оплате',
            self::ADVANCE_GIVEN => 'Выданные авансы',
            self::ADVANCE_RECEIVED => 'Полученные авансы',
            self::UNALLOCATED_OUT => 'Нераспределённые выплаты',
            self::UNALLOCATED_IN => 'Нераспределённые поступления',
        };
    }
}
