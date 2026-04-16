<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Marketplace\Domain\OzonCostCategory;
use Psr\Log\LoggerInterface;

/**
 * Тонкий адаптер над OzonCostCategory для обратной совместимости.
 *
 * Единственный источник истины — OzonCostCategory::all().
 * Этот класс сохраняет прежние сигнатуры методов, чтобы не менять вызывающий код.
 *
 * Используется в: OzonCostsRawProcessor, RestoreMarketplaceCostCategoriesAction,
 *                 CostsDebugController, ReconciliationCreateOvhCategoryController,
 *                 DebugUnknownOperationsController.
 */
final class OzonServiceCategoryMap
{
    /**
     * Версия словаря — обновлять при любом изменении маппинга.
     * Используется в /marketplace/costs/debug/map-version для проверки деплоя.
     */
    public const VERSION = '2026-04-16.3';

    /**
     * Service names из API Ozon, которые являются нулевыми маркерами (price = 0).
     * Не соответствуют никакой категории — должны быть пропущены.
     */
    private const ZERO_MARKERS = [
        'MarketplaceServiceItemReturnNotDelivToCustomer',
        'MarketplaceServiceItemReturnAfterDelivToCustomer',
    ];

    /**
     * Резолвит category code по точному имени service name / operation type.
     * При неизвестном имени — логирует warning и возвращает fallback через fuzzy.
     *
     * @return string|null null = нулевой маркер, пропустить запись
     */
    public static function resolve(string $serviceName, LoggerInterface $logger): ?string
    {
        if (self::isZeroMarker($serviceName)) {
            return null;
        }

        $category = OzonCostCategory::findByServiceName($serviceName)
            ?? OzonCostCategory::findByOperationType($serviceName);

        if ($category !== null) {
            return $category->code;
        }

        $fallback = self::fuzzy($serviceName);

        $logger->warning('ozon_unknown_service_name', [
            'service_name' => $serviceName,
            'resolved_to'  => $fallback,
            'hint'         => 'Add to OzonCostCategory::all()',
        ]);

        return $fallback;
    }

    /**
     * Статистика справочника для debug-эндпоинта.
     */
    public static function getMapStats(): array
    {
        $allCategories = OzonCostCategory::all();
        $totalEntries  = count(self::ZERO_MARKERS);

        foreach ($allCategories as $c) {
            $totalEntries += count($c->serviceNames) + count($c->operationTypes);
        }

        return [
            'version'           => self::VERSION,
            'total_entries'     => $totalEntries,
            'zero_markers'      => count(self::ZERO_MARKERS),
            'unique_categories' => count($allCategories),
        ];
    }

    /**
     * Проверяет является ли service name нулевым маркером (price = 0, пропустить).
     */
    public static function isZeroMarker(string $serviceName): bool
    {
        return in_array($serviceName, self::ZERO_MARKERS, true);
    }

    /**
     * Проверяет известен ли service name / operation type (не нулевой маркер).
     */
    public static function isKnown(string $serviceName): bool
    {
        return OzonCostCategory::findByServiceName($serviceName) !== null
            || OzonCostCategory::findByOperationType($serviceName) !== null;
    }

    /**
     * Все уникальные category codes с человекочитаемыми именами.
     *
     * @return array<string, string> code => human-readable name
     */
    public static function getAllCategoryCodes(): array
    {
        $codes = [];
        foreach (OzonCostCategory::all() as $c) {
            $codes[$c->code] = $c->name;
        }

        return $codes;
    }

    /**
     * Человекочитаемое имя category code.
     */
    public static function getCategoryName(string $categoryCode): string
    {
        return OzonCostCategory::findByCode($categoryCode)?->name ?? 'Прочие услуги Ozon';
    }

    /**
     * Fuzzy fallback для неизвестных service names.
     */
    private static function fuzzy(string $serviceName): string
    {
        $lower = mb_strtolower($serviceName);

        if (str_contains($lower, 'логистик') || str_contains($lower, 'logistic')
            || str_contains($lower, 'магистраль') || str_contains($lower, 'доставк')) {
            return 'ozon_logistic_direct';
        }
        if (str_contains($lower, 'обработк') || str_contains($lower, 'сборк')
            || str_contains($lower, 'fulfillment') || str_contains($lower, 'dropoff')) {
            return 'ozon_fulfillment';
        }
        if (str_contains($lower, 'хранени') || str_contains($lower, 'storage')
            || str_contains($lower, 'размещени')) {
            return 'ozon_storage';
        }
        if (str_contains($lower, 'эквайринг') || str_contains($lower, 'acquiring')) {
            return 'ozon_acquiring';
        }
        if (str_contains($lower, 'продвижени') || str_contains($lower, 'реклам')
            || str_contains($lower, 'promotion') || str_contains($lower, 'клик')) {
            return 'ozon_cpc';
        }
        if (str_contains($lower, 'упаковк') || str_contains($lower, 'package')) {
            return 'ozon_package_materials';
        }
        if (str_contains($lower, 'штраф') || str_contains($lower, 'penalty')
            || str_contains($lower, 'удержани')) {
            return 'ozon_penalty_undeliverable';
        }
        if (str_contains($lower, 'кросс') || str_contains($lower, 'поставк')) {
            return 'ozon_crossdocking';
        }

        return 'ozon_other_service';
    }
}
