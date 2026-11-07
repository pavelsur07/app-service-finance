<?php

declare(strict_types=1);

namespace App\Settlement\Enum;

enum SettlementDocumentStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
}
