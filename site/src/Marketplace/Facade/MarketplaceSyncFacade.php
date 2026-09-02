<?php

declare(strict_types=1);

namespace App\Marketplace\Facade;

use App\Marketplace\Application\Command\FetchMarketplaceDataCommand;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessRawDocumentAction;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanner;
use App\Marketplace\Command\WbFinancialReportsSyncCommand;
use App\Marketplace\DTO\ActiveSellerConnectionDTO;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveSellerConnectionsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MarketplaceSyncFacade
{
    private const WB_FINANCIAL_REPORTS_SYNC_PLANNER = 'WbFinancialReportSyncPlanner';
    private const WB_FINANCIAL_REPORTS_SYNC_COMMAND = 'app:marketplace:wb-financial-reports:sync';

    public function __construct(
        private ActiveSellerConnectionsQuery $activeSellerConnectionsQuery,
        private ProcessRawDocumentAction $processRawDocumentAction,
        private MessageBusInterface $messageBus,
        #[Autowire(service: 'monolog.logger.legacy_wb_sync')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Активные SELLER-подключения для модулей, которым нужно пройти по всем
     * парам (компания, маркетплейс).
     *
     * Через Facade, а не напрямую Query: `Infrastructure/` чужого модуля
     * закрыт, и без этой точки входа Ingestion пришлось бы нарушать границу.
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    public function activeSellerConnections(): array
    {
        $connections = [];
        foreach ($this->activeSellerConnectionsQuery->execute() as $row) {
            $connections[] = new ActiveSellerConnectionDTO(
                connectionRef: (string) $row['id'],
                companyId: (string) $row['company_id'],
                marketplace: (string) $row['marketplace'],
            );
        }

        return $connections;
    }

    public function syncSales(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): int {
        $this->guardLegacyWbSync($marketplace, $companyId, __METHOD__);

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
        $this->guardLegacyWbSync($marketplace, $companyId, __METHOD__);

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
        $this->guardLegacyWbSync($marketplace, $companyId, __METHOD__);

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

    private function guardLegacyWbSync(MarketplaceType $marketplace, string $companyId, string $entrypoint): void
    {
        if (MarketplaceType::WILDBERRIES === $marketplace) {
            $this->logger->error('Legacy WB sync facade fail-fast triggered.', [
                'legacy_event' => 'legacy_wb_sync_fail_fast',
                'company_id' => $companyId,
                'connection_id' => null,
                'command_class' => null,
                'entrypoint_class' => self::class,
                'entrypoint_method' => $entrypoint,
                'message_class' => null,
                'recommended_replacement' => sprintf('%s / %s (%s)', WbFinancialReportSyncPlanner::class, WbFinancialReportsSyncCommand::class, self::WB_FINANCIAL_REPORTS_SYNC_COMMAND),
            ]);

            throw new \DomainException(sprintf('Legacy WB sync отключён. Используйте %s или новую команду %s.', self::WB_FINANCIAL_REPORTS_SYNC_PLANNER, self::WB_FINANCIAL_REPORTS_SYNC_COMMAND));
        }
    }

    public function processCostsFromRaw(string $companyId, string $rawDocId): int
    {
        return ($this->processRawDocumentAction)(new ProcessMarketplaceRawDocumentCommand($companyId, $rawDocId, 'costs'));
    }
}
