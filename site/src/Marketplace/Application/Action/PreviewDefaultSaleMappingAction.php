<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Application\Command\PreviewDefaultSaleMappingCommand;
use App\Marketplace\Application\DTO\DefaultSaleMappingPreviewItem;
use App\Marketplace\Application\DTO\DefaultSaleMappingPreviewResult;
use App\Marketplace\Application\DTO\DefaultSaleMappingRule;
use App\Marketplace\Enum\DefaultSaleMappingPreviewStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Provider\DefaultSaleMappingYamlProvider;
use App\Marketplace\Infrastructure\Query\PLCategoriesByCodeQuery;
use App\Marketplace\Infrastructure\Query\SaleMappingsByAmountSourceQuery;

final readonly class PreviewDefaultSaleMappingAction
{
    public function __construct(
        private DefaultSaleMappingYamlProvider $yamlProvider,
        private PLCategoriesByCodeQuery $plCategoriesByCodeQuery,
        private SaleMappingsByAmountSourceQuery $saleMappingsQuery,
    ) {
    }

    public function __invoke(PreviewDefaultSaleMappingCommand $command): DefaultSaleMappingPreviewResult
    {
        $marketplace = MarketplaceType::tryFrom($command->marketplace);
        if (null === $marketplace) {
            throw new \DomainException(sprintf('Unknown marketplace "%s".', $command->marketplace));
        }

        $rules = $this->yamlProvider->getForMarketplace($marketplace)->getRules();
        if ([] === $rules) {
            return new DefaultSaleMappingPreviewResult($marketplace, []);
        }

        $plCodes = array_values(array_unique(array_map(
            static fn (DefaultSaleMappingRule $rule): string => $rule->getPlCode(),
            $rules,
        )));

        $plByCode = $this->plCategoriesByCodeQuery->fetchIndexed($command->companyId, $plCodes);
        $existingMappings = $this->saleMappingsQuery->fetchIndexed($command->companyId, $marketplace);

        $items = [];
        foreach ($rules as $rule) {
            $items[] = $this->buildItem($rule, $plByCode, $existingMappings);
        }

        return new DefaultSaleMappingPreviewResult($marketplace, $items);
    }

    /**
     * @param array<string, list<array{id: string, code: string, name: string, type: string, flow: string, is_visible: bool}>> $plByCode
     * @param array<string, list<array{id: string, pl_category_id: string, pl_category_name: ?string, is_active: bool, is_negative: bool}>> $existingMappings
     */
    private function buildItem(DefaultSaleMappingRule $rule, array $plByCode, array $existingMappings): DefaultSaleMappingPreviewItem
    {
        $existingForSource = $existingMappings[$rule->getAmountSource()->value] ?? [];

        // Существующее активное правило не трогаем ни при каких условиях: оно могло
        // быть настроено вручную под нестандартное дерево ОПиУ.
        foreach ($existingForSource as $existing) {
            if ($existing['is_active']) {
                return $this->item($rule, null, $existing, DefaultSaleMappingPreviewStatus::SKIPPED_EXISTING, 'Пропущено: правило уже настроено.');
            }
        }

        $plCandidates = $plByCode[$rule->getPlCode()] ?? [];
        if (count($plCandidates) > 1) {
            return $this->item($rule, null, null, DefaultSaleMappingPreviewStatus::INVALID_TARGET_CATEGORY, 'Найдено несколько категорий ОПиУ с таким code.');
        }

        $pl = $plCandidates[0] ?? null;
        if (null === $pl) {
            return $this->item($rule, null, null, DefaultSaleMappingPreviewStatus::MISSING_PL_CATEGORY, 'Категория ОПиУ не найдена у компании.');
        }

        if ('LEAF_INPUT' !== $pl['type']) {
            return $this->item($rule, $pl, null, DefaultSaleMappingPreviewStatus::INVALID_TARGET_CATEGORY, 'Целевая категория ОПиУ должна быть LEAF_INPUT.');
        }

        // Отключённое правило с той же целью занимает уникальный ключ — вставка
        // не пройдёт. Показываем это заранее, а не отчётом «создано 0».
        foreach ($existingForSource as $existing) {
            if ($existing['pl_category_id'] === $pl['id']) {
                return $this->item($rule, $pl, $existing, DefaultSaleMappingPreviewStatus::SKIPPED_EXISTING, 'Пропущено: такое правило есть, но отключено вручную.');
            }
        }

        return $this->item($rule, $pl, null, DefaultSaleMappingPreviewStatus::WILL_CREATE, 'Будет создано новое правило.');
    }

    /**
     * @param array{id: string, code: string, name: string, type: string, flow: string, is_visible: bool}|null $pl
     * @param array{id: string, pl_category_id: string, pl_category_name: ?string, is_active: bool, is_negative: bool}|null $existing
     */
    private function item(DefaultSaleMappingRule $rule, ?array $pl, ?array $existing, DefaultSaleMappingPreviewStatus $status, string $message): DefaultSaleMappingPreviewItem
    {
        return new DefaultSaleMappingPreviewItem(
            marketplace: $rule->getMarketplace(),
            amountSource: $rule->getAmountSource(),
            plCode: $rule->getPlCode(),
            plCategoryId: $pl['id'] ?? null,
            plCategoryName: $pl['name'] ?? null,
            existingMappingId: $existing['id'] ?? null,
            existingPlCategoryName: $existing['pl_category_name'] ?? null,
            // Для существующего правила показываем его собственный знак, а не
            // ожидаемый: иначе экран нарисует «минус» поверх настройки, где на
            // самом деле стоит «плюс», и спрячет ровно ту ошибку, которую
            // автонастройка не имеет права исправить сама.
            isNegative: $existing['is_negative'] ?? $rule->isNegative(),
            expectedNegative: $rule->isNegative(),
            description: $rule->getDescription(),
            status: $status,
            message: $message,
        );
    }
}
