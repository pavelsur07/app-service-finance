<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Service;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayPreviewMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualPreviewTransaction;
use App\Ingestion\Application\Source\Ozon\OzonListingResolver;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Entity\FinancialTransaction;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\FinancialTransactionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OzonAccrualListingRelinker
{
    public const COMPONENT_FILTER_ALL = 'all';
    public const COMPONENT_FILTER_LINKABLE = 'linkable';

    public function __construct(
        private Connection $connection,
        private OzonListingResolver $listingResolver,
        private RawStorageFacade $rawStorageFacade,
        private OzonAccrualByDayPreviewMapper $previewMapper,
        private FinancialTransactionRepository $transactionRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *     selected: int,
     *     resolved: int,
     *     updated: int,
     *     wouldCreateListings: int,
     *     unresolved: int,
     *     rows: list<array{companyId: string, occurredAt: string, externalId: string, listingSku: string, listingId: string, status: string}>
     * }
     */
    public function relink(
        ?string $companyId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limit,
        bool $execute,
        string $componentFilter = self::COMPONENT_FILTER_ALL,
        bool $includeRows = true,
    ): array {
        if (!in_array($componentFilter, [self::COMPONENT_FILTER_ALL, self::COMPONENT_FILTER_LINKABLE], true)) {
            throw new \InvalidArgumentException('Unsupported component filter.');
        }

        $rows = $this->selectRows($companyId, $from, $to, $limit, $componentFilter);
        $recoveredSourceData = $this->recoverListingSourceData($rows);
        $sourceDataByCompany = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeSourceData($row['source_data'] ?? null);
            if (!$this->hasMarketplaceSkuContext($sourceData)) {
                $sourceData = array_merge(
                    $sourceData,
                    $recoveredSourceData[(string) $row['company_id']][(string) $row['raw_record_id']][(string) $row['external_id']] ?? [],
                );
            }

            $sourceDataByCompany[(string) $row['company_id']][(string) $row['id']] = $sourceData;
        }

        $resolutions = [];
        $wouldCreateById = [];
        foreach ($sourceDataByCompany as $rowCompanyId => $sourceDataRows) {
            if ($execute) {
                $resolutions += $this->listingResolver->resolveMany($rowCompanyId, $sourceDataRows);
                continue;
            }

            foreach ($this->listingResolver->previewMany($rowCompanyId, $sourceDataRows) as $transactionId => $preview) {
                $resolutions[$transactionId] = $preview->resolution;
                $wouldCreateById[$transactionId] = $preview->wouldCreate;
            }
        }

        $tableRows = [];
        $resolved = 0;
        $updated = 0;
        $wouldCreateListings = 0;
        $transactionsById = $execute ? $this->fetchTransactionsToUpdate($rows, $resolutions) : [];

        foreach ($rows as $row) {
            $transactionId = (string) $row['id'];
            $resolution = $resolutions[$transactionId] ?? null;
            $status = 'unresolved';

            if (null !== $resolution?->listingId && null !== $resolution->listingSku) {
                ++$resolved;
                $status = $execute ? 'updated' : 'would-update';

                if ($execute) {
                    $transaction = $transactionsById[$transactionId] ?? null;
                    if ($transaction instanceof FinancialTransaction && null === $transaction->getListingId()) {
                        $transaction->setListing($resolution->listingId, $resolution->listingSku);
                        ++$updated;
                    }
                }
            } elseif (!$execute && true === ($wouldCreateById[$transactionId] ?? false) && null !== $resolution?->listingSku) {
                ++$resolved;
                ++$wouldCreateListings;
                $status = 'would-create-listing+update';
            }

            if ($includeRows) {
                $tableRows[] = [
                    'companyId' => (string) $row['company_id'],
                    'occurredAt' => (string) $row['occurred_at'],
                    'externalId' => (string) $row['external_id'],
                    'listingSku' => $resolution?->listingSku ?? '',
                    'listingId' => $resolution?->listingId ?? '',
                    'status' => $status,
                ];
            }
        }

        if ($execute) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        return [
            'selected' => count($rows),
            'resolved' => $resolved,
            'updated' => $updated,
            'wouldCreateListings' => $wouldCreateListings,
            'unresolved' => count($rows) - $resolved,
            'rows' => $tableRows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectRows(
        ?string $companyId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limit,
        string $componentFilter,
    ): array {
        $where = [
            'source = :source',
            'listing_id IS NULL',
            "(external_id LIKE :external_id_prefix OR source_data->>'_ingestion_resource' = :resource_type)",
        ];
        $params = [
            'source' => IngestSource::OZON->value,
            'external_id_prefix' => 'ozon:accrual-by-day:%',
            'resource_type' => OzonResourceType::ACCRUAL_BY_DAY,
        ];

        if (self::COMPONENT_FILTER_LINKABLE === $componentFilter) {
            $where[] = "(external_id NOT LIKE :non_item_fee_prefix OR source_data->>'_ingestion_resource' = :resource_type)";
            $params['non_item_fee_prefix'] = 'ozon:accrual-by-day:%:non_item_fee:%';
        }

        if (null !== $companyId) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = $companyId;
        }

        if (null !== $from) {
            $where[] = 'occurred_at >= :from';
            $params['from'] = $from->setTime(0, 0)->format('Y-m-d H:i:s');
        }

        if (null !== $to) {
            $where[] = 'occurred_at < :to_exclusive';
            $params['to_exclusive'] = $to->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->executeQuery(
            sprintf(
                "SELECT id, company_id, external_id, occurred_at, source_data, raw_record_id
                 FROM ingest_financial_transactions
                 WHERE %s
                 ORDER BY CASE WHEN split_part(external_id, ':', 4) = 'non_item_fee' THEN 1 ELSE 0 END ASC,
                          occurred_at ASC,
                          id ASC
                 LIMIT %d",
                implode(' AND ', $where),
                $limit,
            ),
            $params,
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     */
    private function recoverListingSourceData(array $rows): array
    {
        $rawRecordIdsByCompany = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeSourceData($row['source_data'] ?? null);
            if ($this->hasMarketplaceSkuContext($sourceData)) {
                continue;
            }

            $companyId = $this->stringValue($row['company_id'] ?? null);
            $rawRecordId = $this->stringValue($row['raw_record_id'] ?? null);
            if (null === $companyId || null === $rawRecordId) {
                continue;
            }

            $rawRecordIdsByCompany[$companyId][$rawRecordId] = $rawRecordId;
        }

        $recovered = [];
        foreach ($rawRecordIdsByCompany as $companyId => $rawRecordIds) {
            foreach ($rawRecordIds as $rawRecordId) {
                try {
                    $rawRows = $this->rawStorageFacade->read($rawRecordId, $companyId);
                    $previewRows = $this->previewMapper->preview($companyId, $rawRows, includeSaleRefund: true);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($previewRows as $previewRow) {
                    $sourceData = $this->listingSourceData($previewRow);
                    if ([] === $sourceData) {
                        continue;
                    }

                    $recovered[$companyId][$rawRecordId][$previewRow->sourceKey] = $sourceData;
                }
            }
        }

        return $recovered;
    }

    /**
     * @param list<array<string, mixed>>                                           $rows
     * @param array<string, \App\Ingestion\Application\DTO\ListingResolution|null> $resolutions
     *
     * @return array<string, FinancialTransaction>
     */
    private function fetchTransactionsToUpdate(array $rows, array $resolutions): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $transactionId = (string) $row['id'];
            $resolution = $resolutions[$transactionId] ?? null;
            if (null !== $resolution?->listingId && null !== $resolution->listingSku) {
                $ids[$transactionId] = $transactionId;
            }
        }

        if ([] === $ids) {
            return [];
        }

        $transactionsById = [];
        foreach ($this->transactionRepository->findBy(['id' => array_values($ids)]) as $transaction) {
            if ($transaction instanceof FinancialTransaction) {
                $transactionsById[$transaction->getId()] = $transaction;
            }
        }

        return $transactionsById;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSourceData(mixed $sourceData): array
    {
        if (is_array($sourceData)) {
            return $sourceData;
        }

        if (!is_string($sourceData) || '' === trim($sourceData)) {
            return [];
        }

        $decoded = json_decode($sourceData, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    private function hasMarketplaceSkuContext(array $sourceData): bool
    {
        if (null !== $this->stringValue($sourceData['sku'] ?? $sourceData['marketplace_sku'] ?? $sourceData['marketplaceSku'] ?? null)) {
            return true;
        }

        $item = $sourceData['item'] ?? null;
        if (is_array($item) && null !== $this->stringValue($item['sku'] ?? null)) {
            return true;
        }

        $items = $sourceData['items'] ?? null;
        if (is_array($items) && isset($items[0]) && is_array($items[0])) {
            return null !== $this->stringValue($items[0]['sku'] ?? null);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function listingSourceData(OzonAccrualPreviewTransaction $row): array
    {
        $sourceData = [];
        if (null !== $row->marketplaceSku) {
            $sourceData['sku'] = $row->marketplaceSku;
        }

        if (null !== $row->supplierSku) {
            $sourceData['offer_id'] = $row->supplierSku;
        }

        if (null !== $row->listingName) {
            $sourceData['name'] = $row->listingName;
        }

        return $sourceData;
    }

    private function stringValue(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
