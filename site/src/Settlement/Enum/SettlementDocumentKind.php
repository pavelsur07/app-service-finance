<?php

declare(strict_types=1);

namespace App\Settlement\Enum;

enum SettlementDocumentKind: string
{
    case OPENING = 'opening';
    case OBLIGATION = 'obligation';
    case PAYMENT = 'payment';
    case CORRECTION = 'correction';
    case CLASSIFICATION = 'classification';
    case ALLOCATION_REVERSAL = 'allocation_reversal';
    case DOCUMENT_REVERSAL = 'document_reversal';
}
