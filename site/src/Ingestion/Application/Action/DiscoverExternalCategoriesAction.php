<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategory;
use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryMappingStatus;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DiscoverExternalCategoriesAction
{
    public function __construct(
        private Connection $connection,
        private ExternalCategoryRepository $categoryRepository,
        private ExternalCategoryMappingRepository $mappingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{scanned: int, categoriesCreated: int, categoriesSeen: int, autoMapped: int, unmapped: int}
     */
    public function __invoke(IngestSource $source = IngestSource::OZON, int $limit = 500): array
    {
        if (IngestSource::OZON !== $source) {
            throw new \InvalidArgumentException(sprintf('External category discovery is not implemented for source "%s".', $source->value));
        }

        $limit = max(1, min(5000, $limit));
        $stats = [
            'scanned' => 0,
            'categoriesCreated' => 0,
            'categoriesSeen' => 0,
            'autoMapped' => 0,
            'unmapped' => 0,
        ];
        $categoriesByIdentity = [];
        $mappingKnownByCategoryId = [];

        foreach ($this->unknownOzonAccrualRows($limit) as $row) {
            ++$stats['scanned'];

            $typeId = $this->optionalString($row['type_id'] ?? null);
            $typeName = $this->extractOzonTypeName($this->optionalString($row['label'] ?? null));
            $normalizedKey = OzonAccrualCategoryTaxonomyResolver::typeKey($typeId)
                ?? OzonAccrualCategoryTaxonomyResolver::nameKey($typeName);

            if (null === $normalizedKey) {
                ++$stats['unmapped'];
                continue;
            }

            $scope = $this->scopeFromComponent($this->optionalString($row['component'] ?? null));
            $identityKey = $this->identityKey($scope, $normalizedKey);
            $externalCategory = $categoriesByIdentity[$identityKey] ?? null;

            if ($externalCategory instanceof ExternalCategory) {
                $externalCategory->markSeen($typeId, $typeName);
                ++$stats['categoriesSeen'];
            } else {
                $externalCategory = $this->categoryRepository->findByIdentity(
                    IngestSource::OZON,
                    OzonResourceType::ACCRUAL_BY_DAY,
                    $scope,
                    $normalizedKey,
                );

                if (!$externalCategory instanceof ExternalCategory) {
                    $externalCategory = new ExternalCategory(
                        source: IngestSource::OZON,
                        resourceType: OzonResourceType::ACCRUAL_BY_DAY,
                        scope: $scope,
                        normalizedKey: $normalizedKey,
                        externalTypeId: $typeId,
                        externalName: $typeName,
                        status: ExternalCategoryStatus::NEW,
                    );
                    $this->entityManager->persist($externalCategory);
                    ++$stats['categoriesCreated'];
                } else {
                    $externalCategory->markSeen($typeId, $typeName);
                    ++$stats['categoriesSeen'];
                }

                $categoriesByIdentity[$identityKey] = $externalCategory;
            }

            $mappedCategory = OzonAccrualCategory::findByTypeId($typeId) ?? OzonAccrualCategory::findByOzonName($typeName);
            $categoryId = $externalCategory->getId();
            $mappingKnownByCategoryId[$categoryId] ??= $this->mappingRepository->findByCategory($externalCategory) instanceof ExternalCategoryMapping;

            if ($mappedCategory instanceof OzonAccrualCategory && !$mappingKnownByCategoryId[$categoryId]) {
                $this->entityManager->persist(new ExternalCategoryMapping(
                    externalCategory: $externalCategory,
                    canonicalCode: $mappedCategory->code,
                    canonicalLabel: $mappedCategory->label,
                    canonicalGroup: $mappedCategory->group,
                    transactionType: $mappedCategory->transactionType,
                    sortOrder: $mappedCategory->sortOrder,
                    known: true,
                    status: ExternalCategoryMappingStatus::ACTIVE,
                ));
                $mappingKnownByCategoryId[$categoryId] = true;
                ++$stats['autoMapped'];
            }

            if (!$mappedCategory instanceof OzonAccrualCategory) {
                ++$stats['unmapped'];
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unknownOzonAccrualRows(int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT
                    ft.source_data->>'_ingestion_type_id' AS type_id,
                    ft.source_data->>'_ozon_category_label' AS label,
                    ft.source_data->>'_ingestion_component' AS component,
                    COUNT(*) AS tx_count,
                    MIN(ft.occurred_at) AS first_seen_at,
                    MAX(ft.occurred_at) AS last_seen_at
                 FROM ingest_financial_transactions ft
                 WHERE ft.source = :source
                   AND ft.source_data->>'_ingestion_resource' = :resourceType
                   AND (
                        ft.source_data->>'_ozon_category_known' = 'false'
                        OR NULLIF(ft.source_data->>'_ozon_category_group', '') IS NULL
                        OR ft.source_data->>'_ozon_category_group' IN ('Неизвестные категории Ozon', 'Требует классификации', 'Без группы Ozon')
                        OR ft.source_data->>'_ozon_category_label' LIKE 'Неизвест%%'
                        OR ft.source_data->>'_ozon_category_label' LIKE 'Ozon accrual%%'
                   )
                   AND NULLIF(ft.source_data->>'_ingestion_type_id', '') IS NOT NULL
                 GROUP BY type_id, label, component
                 ORDER BY MAX(ft.occurred_at) DESC, COUNT(*) DESC
                 LIMIT %d",
                $limit,
            ),
            [
                'source' => IngestSource::OZON->value,
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            ],
        );
    }

    private function extractOzonTypeName(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }

        if (1 === preg_match('/^Неизвестная категория Ozon:\s*(.+)$/u', $label, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function scopeFromComponent(?string $component): string
    {
        $component = (string) $component;

        return match (true) {
            str_starts_with($component, 'delivery:') => OzonAccrualCategoryTaxonomyResolver::SCOPE_DELIVERY,
            str_starts_with($component, 'item_fee:') => OzonAccrualCategoryTaxonomyResolver::SCOPE_ITEM,
            str_starts_with($component, 'non_item_fee') => OzonAccrualCategoryTaxonomyResolver::SCOPE_NON_ITEM,
            str_starts_with($component, 'container_fee') => OzonAccrualCategoryTaxonomyResolver::SCOPE_CONTAINER,
            default => OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
        };
    }

    private function identityKey(string $scope, string $normalizedKey): string
    {
        return sprintf('%s:%s', $scope, $normalizedKey);
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
