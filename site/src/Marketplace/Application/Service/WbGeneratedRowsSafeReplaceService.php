<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Psr\Log\LoggerInterface;

final readonly class WbGeneratedRowsSafeReplaceService implements WbGeneratedRowsSafeReplaceServiceInterface
{
    public function __construct(
        private MarketplaceSaleRepository $saleRepository,
        private MarketplaceReturnRepository $returnRepository,
        private MarketplaceCostRepository $costRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function cleanupForRawDocument(Company $company, string $rawDocumentId, \DateTimeImmutable $businessDate): void
    {
        $lockedSales = $this->saleRepository->countDocumentLinkedByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);
        $lockedReturns = $this->returnRepository->countDocumentLinkedByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);
        $lockedCosts = $this->costRepository->countDocumentLinkedByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);

        if (($lockedSales + $lockedReturns + $lockedCosts) > 0) {
            $this->logger->warning('WB partial refresh: linked generated rows will be preserved', [
                'raw_document_id' => $rawDocumentId,
                'company_id' => $company->getId(),
                'business_date' => $businessDate->format('Y-m-d'),
                'locked_sales' => $lockedSales,
                'locked_returns' => $lockedReturns,
                'locked_costs' => $lockedCosts,
            ]);
        }

        $this->saleRepository->deleteByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);
        $this->returnRepository->deleteByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);
        $this->costRepository->deleteByRawDocument($company, MarketplaceType::WILDBERRIES, $rawDocumentId);
    }
}
