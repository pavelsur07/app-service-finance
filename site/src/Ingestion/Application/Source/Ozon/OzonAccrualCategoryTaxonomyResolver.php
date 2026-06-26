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

    /**
     * @var array<string, ExternalCategoryMapping>|null
     */
    private ?array $activeMappingsByIdentity = null;

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

    public function resetPerPreviewState(): void
    {
        $this->activeMappingsByIdentity = null;
        $this->recordedUnknowns = [];
    }

    public function forTypedFee(
        ?string $typeId,
        ?string $typeName,
        ?string $externalCode,
        ?string $providerLabel,
        TransactionType $fallbackType,
        string $scope,
        bool $recordUnknown = false,
    ): OzonAccrualCategory {
        foreach ($this->candidateKeys($externalCode, $providerLabel ?? $typeName, $typeId) as $normalizedKey) {
            $mapped = $this->categoryFromMapping($this->findMapping($scope, $normalizedKey));
            if (null !== $mapped) {
                return $mapped;
            }

            $mapped = $this->categoryFromMapping($this->findMapping(self::SCOPE_ANY, $normalizedKey));
            if (null !== $mapped) {
                return $mapped;
            }
        }

        $static = $this->staticCategory($typeId, $typeName, $externalCode, $providerLabel, $fallbackType);
        if ($static->known) {
            return $static;
        }

        if ($recordUnknown) {
            $this->recordUnknown($scope, $typeId, $typeName, $externalCode, $providerLabel);
        }

        return $static;
    }

    public static function codeKey(?string $externalCode): ?string
    {
        $externalCode = self::normalizeExternalCode($externalCode);

        return null !== $externalCode ? sprintf('code:%s', $externalCode) : null;
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

    public static function normalizeExternalCode(?string $externalCode): ?string
    {
        $externalCode = trim((string) $externalCode);
        if ('' === $externalCode || 'unknown' === strtolower($externalCode)) {
            return null;
        }

        return mb_strtolower($externalCode);
    }

    public static function looksLikeExternalCode(?string $value): bool
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return false;
        }

        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $value)) {
            return false;
        }

        return str_contains($value, '_')
            || str_contains($value, '.')
            || str_contains($value, ':')
            || 1 === preg_match('/[a-z][A-Z]/', $value)
            || str_starts_with(strtolower($value), 'ozon');
    }

    private static function normalizeTypeId(?string $typeId): ?string
    {
        $typeId = trim((string) $typeId);

        return '' !== $typeId && 'unknown' !== strtolower($typeId) ? $typeId : null;
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(?string $externalCode, ?string $providerLabel, ?string $typeId): array
    {
        $keys = [];
        $codeKey = self::codeKey($externalCode);
        if (null !== $codeKey) {
            $keys[] = $codeKey;
        }

        $nameKey = self::nameKey($providerLabel);
        if (null !== $nameKey) {
            $keys[] = $nameKey;
        }

        $typeKey = self::typeKey($typeId);
        if (null !== $typeKey) {
            $keys[] = $typeKey;
        }

        return array_values(array_unique($keys));
    }

    private function staticCategory(
        ?string $typeId,
        ?string $typeName,
        ?string $externalCode,
        ?string $providerLabel,
        TransactionType $fallbackType,
    ): OzonAccrualCategory {
        $externalCode = self::normalizeExternalCode($externalCode);

        return (null !== $externalCode ? OzonAccrualCategory::findByCode($externalCode) : null)
            ?? OzonAccrualCategory::findByOzonName($externalCode)
            ?? OzonAccrualCategory::findByOzonName($providerLabel)
            ?? OzonAccrualCategory::forTypedFee($typeId, $typeName, $fallbackType, $externalCode, $providerLabel);
    }

    private function findMapping(string $scope, string $normalizedKey): ?ExternalCategoryMapping
    {
        return $this->activeMappingsByIdentity()[$this->mappingIdentityKey($scope, $normalizedKey)] ?? null;
    }

    /**
     * @return array<string, ExternalCategoryMapping>
     */
    private function activeMappingsByIdentity(): array
    {
        if (null !== $this->activeMappingsByIdentity) {
            return $this->activeMappingsByIdentity;
        }

        $this->activeMappingsByIdentity = [];
        foreach ($this->mappingRepository->findActiveBySourceAndResource(IngestSource::OZON, OzonResourceType::ACCRUAL_BY_DAY) as $mapping) {
            $category = $mapping->getExternalCategory();
            $this->activeMappingsByIdentity[$this->mappingIdentityKey($category->getScope(), $category->getNormalizedKey())] = $mapping;
        }

        return $this->activeMappingsByIdentity;
    }

    private function mappingIdentityKey(string $scope, string $normalizedKey): string
    {
        return sprintf('%s:%s', $scope, $normalizedKey);
    }

    private function categoryFromMapping(?ExternalCategoryMapping $mapping): ?OzonAccrualCategory
    {
        if (null === $mapping) {
            return null;
        }

        $staticCategory = OzonAccrualCategory::findByCode($mapping->getCanonicalCode());

        return new OzonAccrualCategory(
            code: $mapping->getCanonicalCode(),
            label: $mapping->getCanonicalLabel(),
            group: $mapping->getCanonicalGroup(),
            transactionType: $mapping->getTransactionType(),
            sortOrder: $mapping->getSortOrder(),
            parentLabel: $staticCategory?->parentLabel,
            known: $mapping->isKnown(),
        );
    }

    private function recordUnknown(
        string $scope,
        ?string $typeId,
        ?string $typeName,
        ?string $externalCode,
        ?string $providerLabel,
    ): void
    {
        $normalizedKey = self::codeKey($externalCode)
            ?? self::nameKey($providerLabel ?? $typeName)
            ?? self::typeKey($typeId);
        if (null === $normalizedKey) {
            return;
        }

        $cacheKey = sprintf('%s:%s', $scope, $normalizedKey);
        if (isset($this->recordedUnknowns[$cacheKey])) {
            $this->recordedUnknowns[$cacheKey]->markSeen($typeId, $typeName, externalCode: $externalCode, providerLabel: $providerLabel);

            return;
        }

        $category = $this->categoryRepository->findByIdentity(
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_BY_DAY,
            $scope,
            $normalizedKey,
        );

        if ($category instanceof ExternalCategory) {
            $category->markSeen($typeId, $typeName, externalCode: $externalCode, providerLabel: $providerLabel);
            $this->recordedUnknowns[$cacheKey] = $category;

            return;
        }

        $category = new ExternalCategory(
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            scope: $scope,
            normalizedKey: $normalizedKey,
            externalTypeId: $typeId,
            externalCode: $externalCode,
            externalName: $typeName,
            providerLabel: $providerLabel,
            displayLabel: $providerLabel ?? $typeName,
            status: ExternalCategoryStatus::NEW,
        );

        $this->entityManager->persist($category);
        $this->recordedUnknowns[$cacheKey] = $category;
    }
}
