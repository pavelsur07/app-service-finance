<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Exception\WbRawDocumentRefreshConflictException;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class WbFinancialReportReconciliationService
{
    private const DOCUMENT_TYPE = 'sales_report';
    private const API_ENDPOINT = 'wildberries::finance-sales-reports-detailed';

    public function __construct(
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Caller is responsible for persist/flush of returned raw document.
     */
    public function createOrRefreshRawDocument(
        Company $company,
        MarketplaceConnection $connection,
        \DateTimeImmutable $businessDate,
        array $rows,
        bool $forceRefresh,
    ): MarketplaceRawDocument {
        $existingDocuments = $this->rawDocumentRepository->findActiveExactPeriodDocuments(
            $company,
            MarketplaceType::WILDBERRIES,
            self::DOCUMENT_TYPE,
            self::API_ENDPOINT,
            $businessDate,
            $businessDate,
        );

        $canonical = $existingDocuments[0] ?? null;

        if (count($existingDocuments) > 1) {
            $this->logger->warning('Multiple active WB raw documents found for day, using latest as canonical', [
                'company_id' => $company->getId(),
                'connection_id' => $connection->getId(),
                'business_date' => $businessDate->format('Y-m-d'),
                'raw_document_ids' => array_map(
                    static fn (MarketplaceRawDocument $document): string => $document->getId(),
                    $existingDocuments,
                ),
            ]);
        }

        if ($canonical !== null && $this->isInFlight($canonical)) {
            if ($forceRefresh) {
                throw new WbRawDocumentRefreshConflictException(
                    sprintf('Cannot force refresh raw document %s while pipeline is in-flight.', $canonical->getId()),
                );
            }

            $this->logger->info('Skipping WB raw document refresh: pipeline is still in progress', [
                'company_id' => $company->getId(),
                'connection_id' => $connection->getId(),
                'raw_document_id' => $canonical->getId(),
                'status' => $canonical->getProcessingStatus()?->value,
                'business_date' => $businessDate->format('Y-m-d'),
            ]);

            return $canonical;
        }

        if ($canonical !== null) {
            $canonical->refreshRawData(
                rawData: $rows,
                apiEndpoint: self::API_ENDPOINT,
                recordsCount: count($rows),
            );

            return $canonical;
        }

        $rawDocument = new MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::WILDBERRIES,
            self::DOCUMENT_TYPE,
        );

        $rawDocument->setPeriodFrom($businessDate);
        $rawDocument->setPeriodTo($businessDate);
        $rawDocument->setApiEndpoint(self::API_ENDPOINT);
        $rawDocument->setRawData($rows);
        $rawDocument->setRecordsCount(count($rows));
        $rawDocument->resetProcessingStatus();

        return $rawDocument;
    }

    private function isInFlight(MarketplaceRawDocument $document): bool
    {
        return in_array($document->getProcessingStatus(), [PipelineStatus::PENDING, PipelineStatus::RUNNING], true);
    }
}
