<?php

namespace App\Enum;

enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case TO_DELETE = 'TO_DELETE';
}
