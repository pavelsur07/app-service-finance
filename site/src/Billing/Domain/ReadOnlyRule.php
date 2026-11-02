<?php

declare(strict_types=1);

namespace App\Billing\Domain;

use App\Billing\Enum\AccessStatus;

/**
 * Режим «только чтение» после льготного периода: какие запросы кабинета отклоняются.
 * Изменяющий метод + статус `read_only` + маршрут не из белого списка. `none` (тариф не назначен)
 * и `grace` не ограничиваются.
 */
final readonly class ReadOnlyRule
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Изменяющие маршруты, доступные и в «только чтение»: вход и личные настройки, управление
     * кабинетами (переключиться, создать, удалить свой), принять приглашение в другой кабинет,
     * пересчёт и предпросмотр отчётов — POST без изменения учёта.
     */
    public const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_profile_password',
        'app_ui_mode_switch',
        'company_set_active',
        'company_new',
        'company_delete',
        'company_invite_accept',
        'finance_report_preview_recalc',
        'report_account_balances_recalc',
        'marketplace_cost_pl_mapping_default_preview',
        'marketplace_pl_mappings_default_preview',
    ];

    public static function isMutating(string $method): bool
    {
        return \in_array(strtoupper($method), self::MUTATING_METHODS, true);
    }

    public static function isAllowedRoute(?string $route): bool
    {
        return null !== $route && \in_array($route, self::ALLOWED_ROUTES, true);
    }

    public static function blocks(string $method, ?string $route, AccessStatus $status): bool
    {
        return AccessStatus::READ_ONLY === $status && self::isMutating($method) && !self::isAllowedRoute($route);
    }
}
