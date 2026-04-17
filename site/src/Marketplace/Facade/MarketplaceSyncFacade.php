<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\Command\FetchMarketplaceDataCommand;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MarketplaceSyncFacade
{
    public function __construct(
        private ProcessRawDocumentAction $processRawDocumentAction,
        private MessageBusInterface $messageBus,
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

    /**
     * @deprecated No active callers. Use ProcessMarketplaceRawDocumentAction directly.
     */
    public function processSalesFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'sales'));
    }

    /**
     * @deprecated No active callers. Use ProcessMarketplaceRawDocumentAction directly.
     */
    public function processReturnsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'returns'));
    }

    public function processCostsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'costs'));
    }

}
