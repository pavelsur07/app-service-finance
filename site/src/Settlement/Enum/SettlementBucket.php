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
}
