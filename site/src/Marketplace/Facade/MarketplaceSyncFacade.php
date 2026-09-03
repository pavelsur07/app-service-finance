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
        // Отдельный канал: переполнение реестра подключений к легаси-синку WB
        // отношения не имеет, и складывать его в тот же канал значило бы
        // спрятать инцидент среди сообщений про отключённую фичу.
        private LoggerInterface $connectionsLogger,
    ) {
    }

    /**
     * Активные SELLER-подключения ОДНОЙ компании.
     *
     * Через Facade, а не напрямую Query: `Infrastructure/` чужого модуля
     * закрыт, и без этой точки входа Ingestion пришлось бы нарушать границу.
     *
     * Пагинации здесь нет: у компании столько подключений, сколько у неё
     * кабинетов продавца — единицы. Но «единицы» это наблюдение, а не
     * ограничение схемы, поэтому у выборки есть потолок, и его достижение —
     * инцидент, а не молчаливое усечение.
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    public function activeSellerConnections(string $companyId): array
    {
        $cap = ActiveSellerConnectionsQuery::COMPANY_CONNECTIONS_LIMIT;
        $connections = $this->toDtos($this->activeSellerConnectionsQuery->executeForCompany($companyId, $cap));

        // Потолок выборки — ограничение, а не обещание. Молча обрезанный
        // список выглядел бы как «у компании столько подключений и есть», и
        // необработанные кабинеты были бы неотличимы от несуществующих.
        //
        // Запрос отдаёт на строку больше потолка: ровно `cap` подключений —
        // законная граница, и алерт на ней был бы ложным.
        if (count($connections) > $cap) {
            $this->connectionsLogger->error('Active seller connections hit the per-company cap; some cabinets are not processed.', [
                'company_id' => $companyId,
                'cap' => $cap,
            ]);

            return array_slice($connections, 0, $cap);
        }

        return $connections;
    }

    /**
     * Страница реестра подключений ВСЕХ компаний, keyset-курсор по
     * `connectionRef`.
     *
     * @companyScopeExempt Системный обход: cron-командам нужно пройти по всем
     * парам (компания, маркетплейс), и ограничивать выборку одной компанией
     * здесь нечем. Метод назван отдельно и требует явного лимита, чтобы
     * межкомпанейский проход нельзя было получить случайно, попросив «просто
     * подключения».
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    public function activeSellerConnectionsPage(int $limit, ?string $afterConnectionRef = null): array
    {
        return $this->toDtos($this->activeSellerConnectionsQuery->executePage($limit, $afterConnectionRef));
    }

    /**
     * @param list<array{id: string, company_id: string, marketplace: string}> $rows
     *
     * @return list<ActiveSellerConnectionDTO>
     */
    private function toDtos(array $rows): array
    {
        $connections = [];
        foreach ($rows as $row) {
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
