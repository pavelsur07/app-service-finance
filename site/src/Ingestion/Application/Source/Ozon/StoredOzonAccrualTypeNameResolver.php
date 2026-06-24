<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;

final class StoredOzonAccrualTypeNameResolver
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $cache = [];

    public function __construct(
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
    ) {
    }

    public function resolve(string $companyId, ?string $typeId): ?string
    {
        $typeId = trim((string) $typeId);
        if ('' === $typeId || 'unknown' === strtolower($typeId)) {
            return null;
        }

        $dictionary = $this->dictionary($companyId);

        return $dictionary[$typeId] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $companyId): array
    {
        if (array_key_exists($companyId, $this->cache)) {
            return $this->cache[$companyId];
        }

        $rawRecord = $this->rawRecordRepository->findLatestByCompanySourceExternalId(
            $companyId,
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_TYPES,
            'accrual-types',
        );
        if (null === $rawRecord) {
            return $this->cache[$companyId] = [];
        }

        $dictionary = [];
        foreach ($this->rawStorageFacade->read($rawRecord->getId(), $companyId) as $row) {
            if (!is_array($row) || ($row['_ingestion_empty'] ?? false) === true) {
                continue;
            }

            $typeId = $this->stringValue($row['type_id'] ?? $row['typeId'] ?? $row['id'] ?? null);
            $name = $this->stringValue($row['name'] ?? $row['title'] ?? $row['type_name'] ?? $row['typeName'] ?? null);
            if (null === $typeId || null === $name) {
                continue;
            }

            $dictionary[$typeId] = $name;
        }

        ksort($dictionary);

        return $this->cache[$companyId] = $dictionary;
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_scalar($value) && null !== $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' !== $value ? $value : null;
    }
}
