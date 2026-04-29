<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\PreviewDefaultCostMappingCommand;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewItem;
use App\Marketplace\Application\DTO\DefaultCostMappingPreviewResult;
use App\Marketplace\Application\DTO\DefaultCostMappingRule;
use App\Marketplace\Enum\DefaultCostMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultCostMappingYamlProvider;
use App\Marketplace\Infrastructure\Query\MarketplaceCostCategoriesByCodeQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceCostPLMappingsByCostCategoryQuery;
use App\Marketplace\Infrastructure\Query\PLCategoriesByCodeQuery;

final readonly class PreviewDefaultCostMappingAction
{
    public function __construct(
        private DefaultCostMappingYamlProvider $yamlProvider,
        private MarketplaceCostCategoriesByCodeQuery $costCategoriesByCodeQuery,
        private PLCategoriesByCodeQuery $plCategoriesByCodeQuery,
        private MarketplaceCostPLMappingsByCostCategoryQuery $mappingsByCostCategoryQuery,
    ) {
    }

    public function __invoke(PreviewDefaultCostMappingCommand $command): DefaultCostMappingPreviewResult
    {
        $marketplace = MarketplaceType::tryFrom($command->marketplace);
        if ($marketplace === null) {
            throw new \DomainException(sprintf('Unknown marketplace "%s".', $command->marketplace));
        }

        $rules = $this->yamlProvider->getForMarketplace($marketplace)->getRules();
        if ($rules === []) {
            return new DefaultCostMappingPreviewResult($marketplace, []);
        }

        $costCodes = array_values(array_unique(array_map(static fn(DefaultCostMappingRule $rule): string => $rule->getCostCode(), $rules)));
        $plCodes = array_values(array_unique(array_map(static fn(DefaultCostMappingRule $rule): string => $rule->getPlCode(), $rules)));

        $costByCode = $this->costCategoriesByCodeQuery->fetchIndexed($command->companyId, $marketplace, $costCodes);
        $plByCode = $this->plCategoriesByCodeQuery->fetchIndexed($command->companyId, $plCodes);

        $costCategoryIds = array_values(array_map(static fn(array $row): string => $row['id'], $costByCode));
        $existingMappings = $this->mappingsByCostCategoryQuery->fetchIndexedByCostCategoryId($command->companyId, $costCategoryIds);

        $items = [];
        foreach ($rules as $rule) {
            $items[] = $this->buildItem($rule, $costByCode, $plByCode, $existingMappings);
        }

        return new DefaultCostMappingPreviewResult($marketplace, $items);
    }

    private function buildItem(DefaultCostMappingRule $rule, array $costByCode, array $plByCode, array $existingMappings): DefaultCostMappingPreviewItem
    {
        $cost = $costByCode[$rule->getCostCode()] ?? null;
        $plCandidates = $plByCode[$rule->getPlCode()] ?? [];
        $pl = count($plCandidates) === 1 ? $plCandidates[0] : null;

        if ($cost === null) {
            return $this->item($rule, null, null, null, DefaultCostMappingPreviewStatus::MISSING_COST_CATEGORY, 'Категория затрат не найдена у компании.');
        }

        if (count($plCandidates) > 1) {
            return $this->item($rule, $cost, null, null, DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY, 'Найдено несколько категорий ОПиУ с таким code.');
        }

        if ($pl === null) {
            return $this->item($rule, $cost, null, $existingMappings[$cost['id']] ?? null, DefaultCostMappingPreviewStatus::MISSING_PL_CATEGORY, 'Категория ОПиУ не найдена у компании.');
        }

        if ($pl['type'] !== 'LEAF_INPUT') {
            return $this->item($rule, $cost, $pl, $existingMappings[$cost['id']] ?? null, DefaultCostMappingPreviewStatus::INVALID_TARGET_CATEGORY, 'Целевая категория ОПиУ должна быть LEAF_INPUT.');
        }

        $existing = $existingMappings[$cost['id']] ?? null;
        if ($existing === null) {
            return $this->item($rule, $cost, $pl, null, DefaultCostMappingPreviewStatus::WILL_CREATE, 'Будет создан новый mapping.');
        }

        if ($existing['include_in_pl'] === false) {
            return $this->item($rule, $cost, $pl, $existing, DefaultCostMappingPreviewStatus::SKIPPED_DISABLED, 'Пропущено: mapping отключён (include_in_pl=false).');
        }

        if ($existing['pl_category_id'] === null) {
            return $this->item($rule, $cost, $pl, $existing, DefaultCostMappingPreviewStatus::WILL_FILL_EMPTY, 'Будет заполнено пустое поле категории ОПиУ.');
        }

        return $this->item($rule, $cost, $pl, $existing, DefaultCostMappingPreviewStatus::SKIPPED_EXISTING, 'Пропущено: mapping уже настроен вручную.');
    }

    private function item(DefaultCostMappingRule $rule, ?array $cost, ?array $pl, ?array $existing, DefaultCostMappingPreviewStatus $status, string $message): DefaultCostMappingPreviewItem
    {
        return new DefaultCostMappingPreviewItem(
            marketplace: $rule->getMarketplace(),
            costCode: $rule->getCostCode(),
            costCategoryId: $cost['id'] ?? null,
            costCategoryName: $cost['name'] ?? null,
            plCode: $rule->getPlCode(),
            plCategoryId: $pl['id'] ?? null,
            plCategoryName: $pl['name'] ?? null,
            existingMappingId: $existing['id'] ?? null,
            existingPlCategoryId: $existing['pl_category_id'] ?? null,
            existingPlCategoryName: $existing['pl_category_name'] ?? null,
            includeInPl: $rule->isIncludeInPl(),
            isNegative: $rule->isNegative(),
            confidence: $rule->getConfidence(),
            note: $rule->getNote(),
            status: $status,
            message: $message,
        );
    }
}
