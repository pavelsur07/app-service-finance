<?php

namespace App\Enum;

enum AutoTemplateDirection: string
{
    case ANY = 'any';
    case INFLOW = 'inflow';
    case OUTFLOW = 'outflow';
}
