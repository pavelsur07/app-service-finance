<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashCategorySuggestionDecisionOutcome: string
{
    case ACCEPTED = 'accepted';
    case DISMISSED = 'dismissed';
    /** Подсказка устарела к моменту решения: операция уже со статьёй, удалена или статья недоступна. */
    case STALE = 'stale';
}
