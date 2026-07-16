<?php

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleConditionField: string
{
    case COUNTERPARTY = 'COUNTERPARTY';
    case COUNTERPARTY_NAME = 'COUNTERPARTY_NAME';
    case INN = 'INN';
    case DATE = 'DATE';
    case AMOUNT = 'AMOUNT';
    case DESCRIPTION = 'DESCRIPTION';
    case CURRENCY = 'CURRENCY';
    case IMPORT_SOURCE = 'IMPORT_SOURCE';
    case IS_TRANSFER = 'IS_TRANSFER';
    case DOCUMENT_TYPE = 'DOCUMENT_TYPE';
    case MONEY_ACCOUNT = 'MONEY_ACCOUNT';
}
