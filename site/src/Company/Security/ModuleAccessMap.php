<?php

declare(strict_types=1);

namespace App\Company\Security;

use App\Company\Controller\Api\CounterpartySearchController;
use App\Company\Controller\CompanyController;
use App\Company\Controller\CounterpartyController;
use App\Company\Controller\FinancialResponsibilityCenterController;
use App\Company\Controller\ProfileController;
use App\Company\Controller\ProjectDirectionController;
use App\Shared\Controller\HomeRedirectController;
use App\Shared\Controller\UiModeController;

/**
 * Карта классов контроллеров → модуль доступа.
 * Точные FQCN проверяются раньше namespace-префиксов.
 *
 * Инвариант вызова: isExempt() проверяется ДО resolve() — exempt-префиксы
 * перекрывают модульные (напр. App\Telegram\Controller\Admin\ внутри App\Telegram\).
 */
final class ModuleAccessMap
{
    /**
     * Точные FQCN → модуль (исключения внутри префиксных зон).
     *
     * @var array<string, Module>
     */
    private const EXACT = [
        // Контрагенты, ЦФО и направления проектов живут в App\Company, но относятся к финансам.
        CounterpartyController::class => Module::FINANCE,
        FinancialResponsibilityCenterController::class => Module::FINANCE,
        ProjectDirectionController::class => Module::FINANCE,
    ];

    /**
     * Namespace-префиксы → модуль. Узкий префикс 'App\Cash\Controller\' (а не 'App\Cash\')
     * ограничивает гейт HTTP-слоем Cash; остальные префиксы намеренно покрывают весь модуль.
     *
     * @var array<string, Module>
     */
    private const PREFIXES = [
        'App\\Cash\\Controller\\' => Module::FINANCE,
        'App\\Finance\\' => Module::FINANCE,
        'App\\Balance\\' => Module::FINANCE,
        'App\\Report\\' => Module::FINANCE,
        'App\\Loan\\' => Module::FINANCE,
        'App\\Ai\\' => Module::FINANCE,
        'App\\Marketplace\\' => Module::MARKETPLACE,
        'App\\MarketplaceAds\\' => Module::MARKETPLACE,
        'App\\MarketplaceAnalytics\\' => Module::MARKETPLACE,
        'App\\Inventory\\' => Module::MARKETPLACE,
        'App\\Ingestion\\' => Module::MARKETPLACE,
        'App\\MoySklad\\' => Module::MARKETPLACE,
        'App\\Deals\\' => Module::DEALS,
        'App\\Catalog\\' => Module::CATALOG,
        'App\\Company\\Controller\\' => Module::ADMIN,
        'App\\Billing\\' => Module::ADMIN,
        'App\\Telegram\\' => Module::ADMIN,
    ];

    /**
     * Точные FQCN без модульного гейта (личные/общие экраны, не привязанные к модулю).
     *
     * @var list<string>
     */
    private const EXEMPT_EXACT = [
        ProfileController::class,   // личный профиль (смена пароля), доступен любому участнику
        UiModeController::class,    // переключение темы UI, личная настройка
        CompanyController::class,   // список/создание/переключение компаний; работает и без активной компании, свои owner-проверки
        HomeRedirectController::class, // роутер лендинга «/», доступен любому участнику; сам проверяет права через is_granted
        // Общий company-scoped справочник (контрагенты), доступен любому участнику активной компании
        // (форма сделки в Deals использует /api/counterparties/search); tenant-safe — компания из сессии.
        CounterpartySearchController::class,
    ];

    /**
     * Префиксы без модульного гейта: есть свои гейты (ROLE_ADMIN/ROLE_SUPER_ADMIN)
     * либо общие экраны, которые не должны зависеть от членства в компании.
     *
     * @var list<string>
     */
    private const EXEMPT_PREFIXES = [
        'App\\Admin\\',                          // админка под ROLE_ADMIN
        'App\\Mcp\\',                            // MCP-интеграции со своей авторизацией
        'App\\Analytics\\',                      // общий дашборд/health; Stage 5 разделит виджеты
        'App\\Notification\\',                   // общие уведомления дашборда, не привязаны к модулю
        'App\\Telegram\\Controller\\Admin\\',    // /admin/*, гейт ROLE_ADMIN
        'App\\Marketplace\\Controller\\Admin\\', // маппинг-админка под ROLE_ADMIN, без контекста компании
        'App\\MarketplaceAds\\Controller\\Api\\Admin\\', // админ-API под ROLE_SUPER_ADMIN
    ];

    public function resolve(string $className): ?Module
    {
        if (isset(self::EXACT[$className])) {
            return self::EXACT[$className];
        }

        $matched = null;
        $matchedLength = 0;
        foreach (self::PREFIXES as $prefix => $module) {
            if (str_starts_with($className, $prefix) && \strlen($prefix) > $matchedLength) {
                $matched = $module;
                $matchedLength = \strlen($prefix);
            }
        }

        return $matched;
    }

    public function isExempt(string $className): bool
    {
        if (\in_array($className, self::EXEMPT_EXACT, true)) {
            return true;
        }

        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
