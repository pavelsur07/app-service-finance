<?php

namespace App\Enum;

enum ConditionField: string
{
    case PLAT_INN = 'plat_inn';
    case POL_INN = 'pol_inn';
    case DESCRIPTION = 'description';
    case AMOUNT = 'amount';
    case COUNTERPARTY_NAME_RAW = 'counterparty_name_raw';
    case MONEY_ACCOUNT = 'money_account';
    case DOC_NUMBER = 'doc_number';
    case DATE = 'date';
}
