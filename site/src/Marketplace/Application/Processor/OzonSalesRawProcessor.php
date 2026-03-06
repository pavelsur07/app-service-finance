<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Processor;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\StagingRecordType;
use App\Marketplace\Infrastructure\Query\MarketplaceSaleExistingExternalIdsQuery;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class OzonSalesRawProcessor implements MarketplaceRawProcessorInterface
{
    public function __construct(
        private MarketplaceSaleExistingExternalIdsQuery $existingIdsQuery,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(string|StagingRecordType $type, string $kind = ''): bool
    {
        if ($type instanceof StagingRecordType) {
            return $type === StagingRecordType::SALE;
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function processBatch(string $companyId, MarketplaceType $marketplace, array $rawRows): void
    {
        $externalIds = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['operation_id'] ?? ''),
            $rawRows,
        )));

        if ($externalIds === []) {
            return;
        }

        $existingIds = $this->existingIdsQuery->findExisting($companyId, $marketplace, $externalIds);
        $company = $this->entityManager->getReference(Company::class, $companyId);

        foreach ($rawRows as $row) {
            $operationId = (string) ($row['operation_id'] ?? '');
            if ($operationId === '' || in_array($operationId, $existingIds, true)) {
                continue;
            }

            $existingIds[] = $operationId;

            $amount = (string) ((float) ($row['amount'] ?? 0));
            $sale = new MarketplaceSale(
                Uuid::uuid4()->toString(),
                $company,
                null,
                null,
                $marketplace,
            );

            $sale->setExternalOrderId($operationId);
            $sale->setSaleDate($this->resolveSaleDate($row));
            $sale->setQuantity(1);
            $sale->setPricePerUnit($amount);
            $sale->setTotalRevenue($amount);
            $sale->setRawData($row);

            $this->entityManager->persist($sale);
        }

        $this->entityManager->flush();
    }

    public function process(string $companyId, string $rawDocId): int
    {
        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSaleDate(array $row): \DateTimeImmutable
    {
        $date = (string) ($row['operation_date'] ?? 'now');

        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }
}
