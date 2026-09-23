<?php

declare(strict_types=1);

namespace App\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\OzonAccrualSyncPlanner;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Первичная загрузка истории нового SELLER-подключения: 01.01 текущего года → вчера.
 * За сегодня данные ещё неполные — их загрузит ежедневный cron завтра.
 *
 * WB — дневные статусы планировщика финансовых отчётов (старт — от резолвера).
 * Ozon — задачи by-day через OzonAccrualSyncPlanner; начало окна поднимается до
 * OzonAccrualSyncPlanner::EARLIEST_SAFE_DAY. Прежняя недельная цепочка
 * InitialSyncMessage ходила в /v3/finance/transaction/list, снятый 09.09.2026.
 */
#[AsMessageHandler]
final class TriggerInitialSyncHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly WbInitialSyncStartDateResolver $wbStartDateResolver,
        private readonly WbFinancialReportSyncPlannerInterface $wbFinancialReportSyncPlanner,
        private readonly OzonAccrualSyncPlanner $ozonAccrualSyncPlanner,
    ) {
    }

    public function __invoke(TriggerInitialSyncMessage $message): void
    {
        // InitialSync — это бэкфилл продаж/возвратов/затрат через Seller API.
        // Для Performance-подключений исторических данных по этой цепочке нет,
        // поэтому триггер выполняется только для SELLER.
        $connection = $this->connectionRepository->find($message->connectionId);

        if (!$connection instanceof MarketplaceConnection || MarketplaceConnectionType::SELLER !== $connection->getConnectionType()) {
            $this->logger->warning('InitialSync: skipped — connection missing or not SELLER', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'connection_type' => $connection instanceof MarketplaceConnection ? $connection->getConnectionType()->value : null,
            ]);

            return;
        }

        $yesterday = $this->clock->now()->modify('-1 day')->setTime(0, 0, 0);
        $yearStart = new \DateTimeImmutable((int) $yesterday->format('Y').'-01-01 00:00:00');
        $syncStart = $yearStart;

        if (MarketplaceType::WILDBERRIES === $connection->getMarketplace()) {
            $syncStart = $this->wbStartDateResolver->resolve($connection->getCompany(), $connection);
        }

        if (MarketplaceType::WILDBERRIES === $connection->getMarketplace()) {
            $scheduled = $this->wbFinancialReportSyncPlanner->planInitial(
                companyId: $message->companyId,
                connectionId: $message->connectionId,
                startFrom: $syncStart,
            );

            $this->logger->info('InitialSync WB: planned daily statuses', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'start_from' => $syncStart->format('Y-m-d H:i:s'),
                'end_date' => $yesterday->format('Y-m-d H:i:s'),
                'scheduled_days' => $scheduled,
            ]);

            return;
        }

        if (MarketplaceType::OZON !== $connection->getMarketplace()) {
            $this->logger->warning('InitialSync: skipped — unsupported marketplace', [
                'company_id' => $message->companyId,
                'connection_id' => $message->connectionId,
                'marketplace' => $connection->getMarketplace()->value,
            ]);

            return;
        }

        $plan = $this->ozonAccrualSyncPlanner->planRange(
            $message->companyId,
            $message->connectionId,
            $syncStart,
            $yesterday,
        );

        $this->logger->info('InitialSync Ozon: dispatched accrual by-day tasks', [
            'company_id' => $message->companyId,
            'connection_id' => $message->connectionId,
            'first_day' => $plan->firstDay,
            'last_day' => $plan->lastDay,
            'clamped_to_safe_day' => $plan->clampedToSafeDay,
            'scheduled_days' => $plan->dispatchedCount,
        ]);
    }
}
