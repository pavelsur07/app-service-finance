<?php

namespace App\Enum;

enum CashTransactionAutoRuleConditionField: string
{
    case COUNTERPARTY = 'COUNTERPARTY';
    case COUNTERPARTY_NAME = 'COUNTERPARTY_NAME';
    case INN = 'INN';
    case DATE = 'DATE';
    case AMOUNT = 'AMOUNT';
    case DESCRIPTION = 'DESCRIPTION';
}
