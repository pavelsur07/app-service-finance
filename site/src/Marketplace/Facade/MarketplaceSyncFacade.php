<?php

namespace App\Marketplace\Facade;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\FetchMarketplaceDataCommand;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarketplaceSyncFacade
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProcessRawDocumentAction $processRawDocumentAction,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function syncSales(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $immutableFrom = $fromDate instanceof \DateTimeImmutable ? $fromDate : \DateTimeImmutable::createFromInterface($fromDate);
        $this->messageBus->dispatch(new FetchMarketplaceDataCommand(
            $companyId,
            $marketplace,
            $immutableFrom,
            'sales_report',
            'sales',
        ));

        return 0;
    }

    public function syncCosts(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $immutableFrom = $fromDate instanceof \DateTimeImmutable ? $fromDate : \DateTimeImmutable::createFromInterface($fromDate);
        $this->messageBus->dispatch(new FetchMarketplaceDataCommand(
            $companyId,
            $marketplace,
            $immutableFrom,
            'sales_report',
            'costs',
        ));

        return 0;
    }

    public function syncReturns(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $immutableFrom = $fromDate instanceof \DateTimeImmutable ? $fromDate : \DateTimeImmutable::createFromInterface($fromDate);
        $this->messageBus->dispatch(new FetchMarketplaceDataCommand(
            $companyId,
            $marketplace,
            $immutableFrom,
            'sales_report',
            'returns',
        ));

        return 0;
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
