<?php

declare(strict_types=1);

namespace App\BankSync\Enum;

/**
 * Состояние подключения. Загрузка идёт только у `ACTIVE`; переходы — методы
 * `BankConnection`, матрица — `docs/tasks/bank-sync/stages/stage-3.md`.
 */
enum BankConnectionStatus: string
{
    case ACTIVE = 'active';
    /** Банк отверг токен или его обновление — нужен повторный вход клиента. */
    case NEEDS_REAUTH = 'needs_reauth';
    /** Доступ отозван (согласие отозвано или истекло) — только новое согласие. */
    case REVOKED = 'revoked';
    /** Выключено пользователем. */
    case DISABLED = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Активно',
            self::NEEDS_REAUTH => 'Нужен повторный вход',
            self::REVOKED => 'Доступ отозван',
            self::DISABLED => 'Выключено',
        };
    }
}
