<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageException;
use Psr\Log\LoggerInterface;

final class StoredOzonAccrualTypeNameResolver
{
    /**
     * @var array<string, array{cacheKey: string, dictionary: array<string, string>}>
     */
    private array $cache = [];

    public function __construct(
        private readonly IngestRawRecordRepository $rawRecordRepository,
        private readonly RawStorageFacade $rawStorageFacade,
        private readonly LoggerInterface $logger,
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
        $rawRecord = $this->rawRecordRepository->findLatestByCompanySourceExternalId(
            $companyId,
            IngestSource::OZON,
            OzonResourceType::ACCRUAL_TYPES,
            'accrual-types',
        );
        if (null === $rawRecord) {
            unset($this->cache[$companyId]);

            return [];
        }

        $cacheKey = sprintf('%s:%s', $rawRecord->getId(), $rawRecord->getLastSeenAt()->format('Y-m-d H:i:s.u'));
        if (($this->cache[$companyId]['cacheKey'] ?? null) === $cacheKey) {
            return $this->cache[$companyId]['dictionary'];
        }

        $dictionary = [];
        try {
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
        } catch (ObjectStorageException $exception) {
            $this->logger->warning('Ozon accrual types dictionary object is missing or unreadable; continuing without type names.', [
                'companyId' => $companyId,
                'rawRecordId' => $rawRecord->getId(),
                'storagePath' => $rawRecord->getStoragePath(),
                'exceptionClass' => $exception::class,
                'errorMessage' => $exception->getMessage(),
            ]);

            unset($this->cache[$companyId]);

            return [];
        }

        ksort($dictionary);

        $this->cache[$companyId] = [
            'cacheKey' => $cacheKey,
            'dictionary' => $dictionary,
        ];

        return $dictionary;
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
