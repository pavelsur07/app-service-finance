<?php

namespace App\Marketplace\Facade;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Marketplace\Service\MarketplaceSyncService;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceSyncFacade
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceSyncService $syncService,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly ProcessRawDocumentAction $processRawDocumentAction,
    ) {
    }

    public function syncSales(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $company = $this->requireCompany($companyId);
        $adapter = $this->adapterRegistry->get($marketplace);

        return $this->syncService->syncSales($company, $adapter, $fromDate, $toDate);
    }

    public function syncCosts(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $company = $this->requireCompany($companyId);
        $adapter = $this->adapterRegistry->get($marketplace);

        return $this->syncService->syncCosts($company, $adapter, $fromDate, $toDate);
    }

    public function syncReturns(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $company = $this->requireCompany($companyId);
        $adapter = $this->adapterRegistry->get($marketplace);

        return $this->syncService->syncReturns($company, $adapter, $fromDate, $toDate);
    }

    public function processSalesFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'sales'));
    }

    public function processReturnsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'returns'));
    }

    public function processCostsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'costs'));
    }

    private function requireCompany(string $companyId): Company
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        return $company;
    }
}
