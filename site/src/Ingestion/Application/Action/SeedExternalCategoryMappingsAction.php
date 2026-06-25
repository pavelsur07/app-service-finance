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
use Doctrine\ORM\EntityManagerInterface;

final readonly class SeedExternalCategoryMappingsAction
{
    public function __construct(
        private ExternalCategoryRepository $categoryRepository,
        private ExternalCategoryMappingRepository $mappingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{categoriesCreated: int, categoriesSeen: int, mappingsCreated: int, mappingsExisting: int}
     */
    public function __invoke(IngestSource $source = IngestSource::OZON): array
    {
        if (IngestSource::OZON !== $source) {
            throw new \InvalidArgumentException(sprintf('Default external category seeding is not implemented for source "%s".', $source->value));
        }

        $stats = [
            'categoriesCreated' => 0,
            'categoriesSeen' => 0,
            'mappingsCreated' => 0,
            'mappingsExisting' => 0,
        ];

        foreach (OzonAccrualCategory::all() as $category) {
            foreach ($this->ozonIdentities($category) as $identity) {
                $externalCategory = $this->categoryRepository->findByIdentity(
                    IngestSource::OZON,
                    OzonResourceType::ACCRUAL_BY_DAY,
                    OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
                    $identity['normalizedKey'],
                );

                if (!$externalCategory instanceof ExternalCategory) {
                    $externalCategory = new ExternalCategory(
                        source: IngestSource::OZON,
                        resourceType: OzonResourceType::ACCRUAL_BY_DAY,
                        scope: OzonAccrualCategoryTaxonomyResolver::SCOPE_ANY,
                        normalizedKey: $identity['normalizedKey'],
                        externalTypeId: $identity['externalTypeId'],
                        externalName: $identity['externalName'],
                        status: ExternalCategoryStatus::MAPPED,
                    );
                    $this->entityManager->persist($externalCategory);
                    ++$stats['categoriesCreated'];
                } else {
                    $externalCategory->markSeen($identity['externalTypeId'], $identity['externalName']);
                    ++$stats['categoriesSeen'];
                }

                $mapping = $this->mappingRepository->findByCategory($externalCategory);
                if ($mapping instanceof ExternalCategoryMapping) {
                    ++$stats['mappingsExisting'];
                    continue;
                }

                $this->entityManager->persist(new ExternalCategoryMapping(
                    externalCategory: $externalCategory,
                    canonicalCode: $category->code,
                    canonicalLabel: $category->label,
                    canonicalGroup: $category->group,
                    transactionType: $category->transactionType,
                    sortOrder: $category->sortOrder,
                    known: true,
                    status: ExternalCategoryMappingStatus::ACTIVE,
                ));
                ++$stats['mappingsCreated'];
            }
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @return list<array{normalizedKey: string, externalTypeId: ?string, externalName: ?string}>
     */
    private function ozonIdentities(OzonAccrualCategory $category): array
    {
        $identities = [];

        foreach ($category->typeIds as $typeId) {
            $normalizedKey = OzonAccrualCategoryTaxonomyResolver::typeKey($typeId);
            if (null !== $normalizedKey) {
                $identities[$normalizedKey] = [
                    'normalizedKey' => $normalizedKey,
                    'externalTypeId' => (string) $typeId,
                    'externalName' => null,
                ];
            }
        }

        foreach (array_merge([$category->label], $category->aliases) as $alias) {
            $normalizedKey = OzonAccrualCategoryTaxonomyResolver::nameKey($alias);
            if (null !== $normalizedKey) {
                $identities[$normalizedKey] = [
                    'normalizedKey' => $normalizedKey,
                    'externalTypeId' => null,
                    'externalName' => (string) $alias,
                ];
            }
        }

        return array_values($identities);
    }
}
