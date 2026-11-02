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
     * кабинетами (переключиться, создать, удалить свой), принять приглашение в другой кабинет;
     * отзыв доступа (сотрудник, приглашение, ключи API, интеграции) — безопасность важнее оплаты;
     * пересчёт отчётов (пересобирает производные данные из исходных, ничего не вводит) и
     * предпросмотр. Вход/выход обрабатывает firewall раньше гейта — в списке для полноты.
     */
    public const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'company_member_disable',
        'company_member_disable_legacy',
        'company_invite_revoke',
        'company_invite_revoke_legacy',
        'settings_api_revoke',
        'settings_report_api_key_revoke',
        'bank_sync_account_unlink',
        'moysklad_connections_disable',
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

    /**
     * Маршруты, которые меняют компанию из пути `{id}`, а не активную из сессии. Для маршрутов
     * с параметром `{companyId}` целевая компания берётся из него всегда.
     */
    public const COMPANY_ID_ROUTES = [
        'company_edit',
        'company_tax_profile_save',
        'company_tax_profile_delete',
    ];

    /**
     * ID компании, данные которой меняет маршрут, если он задан в пути; иначе null (активная компания).
     *
     * @param array<string, mixed> $routeParams
     */
    public static function targetCompanyId(?string $route, array $routeParams): ?string
    {
        $id = $routeParams['companyId'] ?? (\in_array($route, self::COMPANY_ID_ROUTES, true) ? ($routeParams['id'] ?? null) : null);

        return \is_string($id) && '' !== $id ? $id : null;
    }

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
