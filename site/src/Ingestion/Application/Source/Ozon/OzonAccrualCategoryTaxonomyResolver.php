<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Entity\ExternalCategory;
use App\Ingestion\Entity\ExternalCategoryMapping;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\TransactionType;
use App\Ingestion\Repository\ExternalCategoryMappingRepository;
use App\Ingestion\Repository\ExternalCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OzonAccrualCategoryTaxonomyResolver
{
    public const SCOPE_ANY = 'any';
    public const SCOPE_FIELD = 'field';
    public const SCOPE_DELIVERY = 'delivery';
    public const SCOPE_ITEM = 'item';
    public const SCOPE_NON_ITEM = 'non_item';
    public const SCOPE_CONTAINER = 'container';

    /**
     * @var array<string, ExternalCategory>
     */
    private array $recordedUnknowns = [];

    public function __construct(
        private readonly ExternalCategoryRepository $categoryRepository,
        private readonly ExternalCategoryMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function forField(string $field, int $signedAmountMinor, bool $recordUnknown = false): ?OzonAccrualCategory
    {
        $static = OzonAccrualCategory::forField($field, $signedAmountMinor);
        if (null === $static) {
            return null;
        }

        return $this->categoryFromMapping($this->findMapping(self::SCOPE_FIELD, self::fieldKey($field))) ?? $static;
    }

    public function forTypedFee(
        ?string $typeId,
        ?string $typeName,
        TransactionType $fallbackType,
        string $scope,
        bool $recordUnknown = false,
    ): OzonAccrualCategory {
        foreach ($this->candidateKeys($typeId, $typeName) as $normalizedKey) {
            $mapped = $this->categoryFromMapping($this->findMapping($scope, $normalizedKey));
            if (null !== $mapped) {
                return $mapped;
            }

            $mapped = $this->categoryFromMapping($this->findMapping(self::SCOPE_ANY, $normalizedKey));
            if (null !== $mapped) {
                return $mapped;
            }
        }

        $static = OzonAccrualCategory::forTypedFee($typeId, $typeName, $fallbackType);
        if ($static->known) {
            return $static;
        }

        if ($recordUnknown) {
            $this->recordUnknown($scope, $typeId, $typeName);
        }

        return $static;
    }

    public static function typeKey(?string $typeId): ?string
    {
        $typeId = self::normalizeTypeId($typeId);

        return null !== $typeId ? sprintf('type:%s', $typeId) : null;
    }

    public static function nameKey(?string $name): ?string
    {
        $name = self::normalizeName($name);

        return null !== $name ? sprintf('name:%s', $name) : null;
    }

    public static function fieldKey(string $field): string
    {
        return sprintf('field:%s', strtolower(trim($field)));
    }

    public static function normalizeName(?string $name): ?string
    {
        $name = trim((string) $name);
        if ('' === $name) {
            return null;
        }

        $name = mb_strtolower($name);
        $name = str_replace('ё', 'е', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return $name;
    }

    private static function normalizeTypeId(?string $typeId): ?string
    {
        $typeId = trim((string) $typeId);

        return '' !== $typeId && 'unknown' !== strtolower($typeId) ? $typeId : null;
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(?string $typeId, ?string $typeName): array
    {
        $keys = [];
        $typeKey = self::typeKey($typeId);
        if (null !== $typeKey) {
            $keys[] = $typeKey;
        }

        $nameKey = self::nameKey($typeName);
        if (null !== $nameKey) {
            $keys[] = $nameKey;
        }

        return array_values(array_unique($keys));
    }

    private function findMapping(string $scope, string $normalizedKey): ?ExternalCategoryMapping
    {
        return $this->mappingRepository->findActiveByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            $scope,
            $normalizedKey,
        );
    }

    private function categoryFromMapping(?ExternalCategoryMapping $mapping): ?OzonAccrualCategory
    {
        if (null === $mapping) {
            return null;
        }

        return new OzonAccrualCategory(
            code: $mapping->getCanonicalCode(),
            label: $mapping->getCanonicalLabel(),
            group: $mapping->getCanonicalGroup(),
            transactionType: $mapping->getTransactionType(),
            sortOrder: $mapping->getSortOrder(),
            known: $mapping->isKnown(),
        );
    }

    private function recordUnknown(string $scope, ?string $typeId, ?string $typeName): void
    {
        $normalizedKey = self::typeKey($typeId) ?? self::nameKey($typeName);
        if (null === $normalizedKey) {
            return;
        }

        $cacheKey = sprintf('%s:%s', $scope, $normalizedKey);
        if (isset($this->recordedUnknowns[$cacheKey])) {
            $this->recordedUnknowns[$cacheKey]->markSeen($typeId, $typeName);

            return;
        }

        $category = $this->categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            $scope,
            $normalizedKey,
        );

        if ($category instanceof ExternalCategory) {
            $category->markSeen($typeId, $typeName);
            $this->recordedUnknowns[$cacheKey] = $category;

            return;
        }

        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: $scope,
            normalizedKey: $normalizedKey,
            externalTypeId: $typeId,
            externalName: $typeName,
            status: ExternalCategoryStatus::NEW,
        );

        $this->entityManager->persist($category);
        $this->recordedUnknowns[$cacheKey] = $category;
    }
}
