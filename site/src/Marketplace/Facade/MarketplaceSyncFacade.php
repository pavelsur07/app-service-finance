<?php

namespace App\Marketplace\Facade;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Service\MarketplaceSyncService;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceSyncFacade
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketplaceSyncService $syncService,
    ) {
    }

    public function processSalesFromRaw(string $companyId, string $rawDocId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: '.$rawDocId);
        }

        if ((string) $rawDoc->getCompany()->getId() !== $companyId) {
            throw new \RuntimeException('Raw document does not belong to company');
        }

        return $this->syncService->processSalesFromRaw($company, $rawDoc);
    }

    public function processReturnsFromRaw(string $companyId, string $rawDocId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: '.$rawDocId);
        }

        if ((string) $rawDoc->getCompany()->getId() !== $companyId) {
            throw new \RuntimeException('Raw document does not belong to company');
        }

        return $this->syncService->processReturnsFromRaw($company, $rawDoc);
    }

    public function processCostsFromRaw(string $companyId, string $rawDocId): int
    {
        $company = $this->em->find(Company::class, $companyId);
        if (!$company instanceof Company) {
            throw new \RuntimeException('Company not found: '.$companyId);
        }

        $rawDoc = $this->em->find(MarketplaceRawDocument::class, $rawDocId);
        if (!$rawDoc instanceof MarketplaceRawDocument) {
            throw new \RuntimeException('Raw document not found: '.$rawDocId);
        }

        if ((string) $rawDoc->getCompany()->getId() !== $companyId) {
            throw new \RuntimeException('Raw document does not belong to company');
        }

        return $this->syncService->processCostsFromRaw($company, $rawDoc);
    }
}
