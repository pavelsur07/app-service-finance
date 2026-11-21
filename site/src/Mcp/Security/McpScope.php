<?php

declare(strict_types=1);

namespace App\Mcp\Security;

enum McpScope: string
{
    case AccountsRead = 'accounts.read';
    case CashCategoriesRead = 'cash_categories.read';
    case CashTransactionsRead = 'cash_transactions.read';
    case CashDraftsCreate = 'cash_drafts.create';

    public function label(): string
    {
        return match ($this) {
            self::AccountsRead => 'Просматривать список денежных счетов',
            self::CashCategoriesRead => 'Просматривать статьи ДДС',
            self::CashTransactionsRead => 'Читать операции ДДС',
            self::CashDraftsCreate => 'Создавать черновики платежей',
        };
    }
}
