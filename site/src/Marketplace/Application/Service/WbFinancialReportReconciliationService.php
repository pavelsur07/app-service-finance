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
        $existingDocuments = $this->rawDocumentRepository->findActiveExactDayDocuments(
            $company,
            MarketplaceType::WILDBERRIES,
            self::DOCUMENT_TYPE,
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

        if (null !== $canonical && $this->isInFlight($canonical)) {
            if ($forceRefresh) {
                throw new WbRawDocumentRefreshConflictException(sprintf('Cannot force refresh raw document %s while pipeline is in-flight.', $canonical->getId()));
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

        if (null !== $canonical) {
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

    /**
     * Starts or resumes a staging raw document for page-by-page WB finance loading.
     * Caller is responsible for persist/flush of returned raw document.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function appendRawDocumentPage(
        Company $company,
        MarketplaceConnection $connection,
        \DateTimeImmutable $businessDate,
        array $rows,
        bool $forceRefresh,
        ?string $rawDocumentId = null,
    ): MarketplaceRawDocument {
        $rawDocument = null !== $rawDocumentId
            ? $this->rawDocumentRepository->find($rawDocumentId)
            : null;

        if (null !== $rawDocumentId && !$rawDocument instanceof MarketplaceRawDocument) {
            throw new \InvalidArgumentException(sprintf('Raw document %s was not found for WB finance day sync continuation.', $rawDocumentId));
        }

        if ($rawDocument instanceof MarketplaceRawDocument) {
            if ((string) $rawDocument->getCompany()->getId() !== (string) $company->getId()
                || MarketplaceType::WILDBERRIES !== $rawDocument->getMarketplace()
                || self::DOCUMENT_TYPE !== $rawDocument->getDocumentType()
                || self::API_ENDPOINT !== $rawDocument->getApiEndpoint()
                || $rawDocument->getPeriodFrom()->format('Y-m-d') !== $businessDate->format('Y-m-d')
                || $rawDocument->getPeriodTo()->format('Y-m-d') !== $businessDate->format('Y-m-d')
            ) {
                throw new \InvalidArgumentException(sprintf('Raw document %s does not match WB finance day sync context.', $rawDocumentId));
            }

            $rawDocument->appendRawDataRows($rows);

            return $rawDocument;
        }

        return $this->createOrRefreshRawDocument($company, $connection, $businessDate, $rows, $forceRefresh);
    }

    private function isInFlight(MarketplaceRawDocument $document): bool
    {
        return in_array($document->getProcessingStatus(), [PipelineStatus::LOADING, PipelineStatus::PENDING, PipelineStatus::RUNNING], true);
    }
}
