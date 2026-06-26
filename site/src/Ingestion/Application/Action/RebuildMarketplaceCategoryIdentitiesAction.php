<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Source\Ozon\OzonAccrualCategoryTaxonomyResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\ExternalCategoryStatus;
use App\Ingestion\Enum\IngestSource;
use Doctrine\DBAL\Connection;

final readonly class RebuildMarketplaceCategoryIdentitiesAction
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{scanned: int, updated: int, deprecated: int, unchanged: int, skipped: int, conflicts: int}
     */
    public function rebuild(IngestSource $source, bool $execute): array
    {
        if (IngestSource::OZON !== $source) {
            throw new \InvalidArgumentException(sprintf('Identity rebuild is not implemented for source "%s".', $source->value));
        }

        $stats = [
            'scanned' => 0,
            'updated' => 0,
            'deprecated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'conflicts' => 0,
        ];

        $rows = $this->categoryRows($source);
        $semanticByType = $this->semanticRowsByType($rows);

        foreach ($rows as $row) {
            ++$stats['scanned'];
            $identity = $this->desiredIdentity($row);
            if (null === $identity) {
                if ($this->deprecateStaleTypeOnlyRow($row, $semanticByType, $execute)) {
                    ++$stats['deprecated'];
                    ++$stats['conflicts'];
                    continue;
                }

                ++$stats['skipped'];
                continue;
            }

            if ($identity['normalizedKey'] === (string) $row['normalized_key']) {
                if ($this->hasMissingSemanticFields($row, $identity)) {
                    if ($execute) {
                        $this->updateCategory((string) $row['id'], $identity);
                    }
                    ++$stats['updated'];
                } else {
                    ++$stats['unchanged'];
                }
                continue;
            }

            $targetId = $this->findIdentityId(
                source: (string) $row['source'],
                resourceType: (string) $row['resource_type'],
                scope: (string) $row['scope'],
                normalizedKey: $identity['normalizedKey'],
            );

            if (null === $targetId) {
                if ($execute) {
                    $this->updateCategory((string) $row['id'], $identity);
                }
                ++$stats['updated'];
                continue;
            }

            if ($targetId !== (string) $row['id']) {
                if ($execute) {
                    $this->updateCategory($targetId, $identity);
                    $this->deprecateCategory((string) $row['id']);
                }
                ++$stats['deprecated'];
                ++$stats['conflicts'];
                continue;
            }

            ++$stats['unchanged'];
        }

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryRows(IngestSource $source): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id,
                    source,
                    resource_type,
                    scope,
                    normalized_key,
                    external_type_id,
                    external_code,
                    external_name,
                    provider_label,
                    display_label,
                    status,
                    seen_count,
                    first_seen_at,
                    last_seen_at
             FROM ingest_external_categories
             WHERE source = :source
               AND resource_type = :resourceType
             ORDER BY last_seen_at DESC, created_at DESC',
            [
                'source' => $source->value,
                'resourceType' => OzonResourceType::ACCRUAL_BY_DAY,
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private function semanticRowsByType(array $rows): array
    {
        $semanticByType = [];

        foreach ($rows as $row) {
            $typeId = $this->optionalString($row['external_type_id'] ?? null);
            if (
                null === $typeId
                || (string) $row['status'] === ExternalCategoryStatus::DEPRECATED->value
                || !$this->hasSemanticIdentity($row)
            ) {
                continue;
            }

            $semanticByType[$this->typeIdentityKey($row)] = (string) $row['id'];
        }

        return $semanticByType;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $semanticByType
     */
    private function deprecateStaleTypeOnlyRow(array $row, array $semanticByType, bool $execute): bool
    {
        if ((string) $row['status'] === ExternalCategoryStatus::DEPRECATED->value) {
            return false;
        }

        $normalizedKey = (string) $row['normalized_key'];
        if (!str_starts_with($normalizedKey, 'type:') || $this->hasSemanticIdentity($row)) {
            return false;
        }

        $semanticId = $semanticByType[$this->typeIdentityKey($row)] ?? null;
        if (null === $semanticId || $semanticId === (string) $row['id']) {
            return false;
        }

        if ($execute) {
            $this->deprecateCategory((string) $row['id']);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasSemanticIdentity(array $row): bool
    {
        return null !== $this->optionalString($row['external_code'] ?? null)
            || null !== $this->optionalString($row['provider_label'] ?? null)
            || null !== $this->semanticExternalName($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function semanticExternalName(array $row): ?string
    {
        $externalName = $this->optionalString($row['external_name'] ?? null);
        if (null === $externalName) {
            return null;
        }

        if (OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode($externalName)) {
            return $externalName;
        }

        return $this->extractOzonTypeName($externalName) ?? $externalName;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function typeIdentityKey(array $row): string
    {
        return implode("\n", [
            (string) $row['source'],
            (string) $row['resource_type'],
            (string) $row['scope'],
            (string) $row['external_type_id'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{normalizedKey: string, externalCode: ?string, providerLabel: ?string, displayLabel: ?string}|null
     */
    private function desiredIdentity(array $row): ?array
    {
        $externalCode = $this->optionalString($row['external_code'] ?? null);
        $externalName = $this->optionalString($row['external_name'] ?? null);
        $providerLabel = $this->optionalString($row['provider_label'] ?? null);
        $displayLabel = $this->optionalString($row['display_label'] ?? null);

        if (null === $externalCode && null !== $externalName && OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode($externalName)) {
            $externalCode = $externalName;
        }

        if (null === $providerLabel && null !== $externalName && !OzonAccrualCategoryTaxonomyResolver::looksLikeExternalCode($externalName)) {
            $providerLabel = $this->extractOzonTypeName($externalName) ?? $externalName;
        }

        $normalizedKey = OzonAccrualCategoryTaxonomyResolver::codeKey($externalCode)
            ?? OzonAccrualCategoryTaxonomyResolver::nameKey($providerLabel);

        if (null === $normalizedKey) {
            return null;
        }

        return [
            'normalizedKey' => $normalizedKey,
            'externalCode' => $externalCode,
            'providerLabel' => $providerLabel,
            'displayLabel' => $displayLabel ?? $providerLabel,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{normalizedKey: string, externalCode: ?string, providerLabel: ?string, displayLabel: ?string} $identity
     */
    private function hasMissingSemanticFields(array $row, array $identity): bool
    {
        return (null === $this->optionalString($row['external_code'] ?? null) && null !== $identity['externalCode'])
            || (null === $this->optionalString($row['provider_label'] ?? null) && null !== $identity['providerLabel'])
            || (null === $this->optionalString($row['display_label'] ?? null) && null !== $identity['displayLabel']);
    }

    /**
     * @param array{normalizedKey: string, externalCode: ?string, providerLabel: ?string, displayLabel: ?string} $identity
     */
    private function updateCategory(string $id, array $identity): void
    {
        $this->connection->executeStatement(
            'UPDATE ingest_external_categories
                SET normalized_key = :normalizedKey,
                    external_code = COALESCE(external_code, :externalCode),
                    provider_label = COALESCE(provider_label, :providerLabel),
                    display_label = COALESCE(display_label, :displayLabel),
                    updated_at = :updatedAt
              WHERE id = :id',
            [
                'id' => $id,
                'normalizedKey' => $identity['normalizedKey'],
                'externalCode' => $identity['externalCode'],
                'providerLabel' => $identity['providerLabel'],
                'displayLabel' => $identity['displayLabel'],
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    private function deprecateCategory(string $id): void
    {
        $this->connection->executeStatement(
            'UPDATE ingest_external_categories
                SET status = :status,
                    updated_at = :updatedAt
              WHERE id = :id',
            [
                'id' => $id,
                'status' => ExternalCategoryStatus::DEPRECATED->value,
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    private function findIdentityId(string $source, string $resourceType, string $scope, string $normalizedKey): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT id
             FROM ingest_external_categories
             WHERE source = :source
               AND resource_type = :resourceType
               AND scope = :scope
               AND normalized_key = :normalizedKey
             LIMIT 1',
            [
                'source' => $source,
                'resourceType' => $resourceType,
                'scope' => $scope,
                'normalizedKey' => $normalizedKey,
            ],
        );

        return false === $id ? null : (string) $id;
    }

    private function extractOzonTypeName(string $label): ?string
    {
        if (1 === preg_match('/^Неизвестная категория Ozon:\s*(.+)$/u', $label, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
