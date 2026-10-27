<?php

declare(strict_types=1);

namespace App\BankSync\Enum;

/**
 * Итог сверки согласий для одного подключения.
 */
enum ConsentState: string
{
    /** Есть действующее согласие — известен его последний день. */
    case ACTIVE = 'active';
    /** Согласия пользователя есть, но все неактивны или истекли — нужен новый вход. */
    case EXPIRED = 'expired';
    /** Банк о согласиях пользователя не сообщил — ничего не меняем. */
    case NOT_FOUND = 'not_found';
}
