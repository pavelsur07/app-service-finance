<?php

namespace App\Company\Enum;

enum CounterpartyType: string
{
    case LEGAL_ENTITY = 'LEGAL_ENTITY';
    case INDIVIDUAL_ENTREPRENEUR = 'INDIVIDUAL_ENTREPRENEUR';
    case SELF_EMPLOYED = 'SELF_EMPLOYED';
    case NATURAL_PERSON = 'NATURAL_PERSON';
}
