<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Application\DTO\SyncConnectionResult;
use App\Marketplace\Application\Service\OzonAccrualSyncPlanner;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ManualSyncNotSupportedException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Wildberries\Application\FinancialReport\WbFinancialReportSyncPlannerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ручная синхронизация подключения: только ставит асинхронные задачи.
 *
 * WB — дневные задачи новой финансовой синхронизации (планировщик статусов).
 * Ozon SELLER — задачи загрузки начислений by-day (OzonAccrualSyncPlanner), тот
 * же путь, что у ночного cron. Прежняя прямая загрузка через /v3/finance/
 * transaction/list снята вместе с эндпоинтом (Ozon, 09.09.2026).
 *
 * Используется из:
 *   - MarketplaceController::syncConnection()       — синхронизация за последние 7 дней
 *   - MarketplaceController::syncConnectionPeriod() — синхронизация за произвольный период
 */
final class SyncConnectionAction
{
    private const WB_MANUAL_RANGE_LIMIT_DAYS = 31;

    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly EntityManagerInterface $em,
        private readonly WbFinancialReportSyncPlannerInterface $wbFinancialReportSyncPlanner,
        private readonly OzonAccrualSyncPlanner $ozonAccrualSyncPlanner,
    ) {
    }

    public function __invoke(SyncConnectionCommand $command): SyncConnectionResult
    {
        $connection = $this->connectionRepository->find($command->connectionId);

        if (!$connection instanceof MarketplaceConnection || (string) $connection->getCompany()->getId() !== $command->companyId) {
            throw new \DomainException('Подключение не найдено');
        }

        $marketplace = $connection->getMarketplace();
        $isOzonSeller = MarketplaceType::OZON === $marketplace
            && MarketplaceConnectionType::SELLER === $connection->getConnectionType();

        if (MarketplaceType::WILDBERRIES !== $marketplace && !$isOzonSeller) {
            throw new ManualSyncNotSupportedException(sprintf('Ручная синхронизация для %s не поддерживается', $marketplace->getDisplayName()));
        }

        if (MarketplaceType::WILDBERRIES === $marketplace) {
            $connection->markSyncStarted();
            $this->em->flush();

            try {
                return new SyncConnectionResult($this->planWbManualSync($command), null, null, false);
            } catch (\Exception $e) {
                $connection->markSyncFailed($e->getMessage());
                $this->em->flush();

                throw $e;
            }
        }

        // Ozon: состояние подключения трогаем только если задачи реально поставлены.
        // Пустое окно (всё до порога EARLIEST_SAFE_DAY или только сегодня) — не
        // синхронизация, и `lastSyncAt` не должен выдавать её за «synced».
        $plan = $this->ozonAccrualSyncPlanner->planRange(
            $command->companyId,
            $command->connectionId,
            $command->fromDate,
            $command->toDate,
        );

        if ($plan->dispatchedCount > 0) {
            $connection->markSyncStarted();
            $this->em->flush();
        }

        return new SyncConnectionResult($plan->dispatchedCount, $plan->firstDay, $plan->lastDay, $plan->clampedToSafeDay);
    }

    private function planWbManualSync(SyncConnectionCommand $command): int
    {
        $from = $this->normalizeWbBusinessDate($command->fromDate);
        $to = $this->normalizeWbBusinessDate($command->toDate);

        if ($from > $to) {
            throw new \DomainException('Дата начала должна быть меньше или равна дате окончания');
        }

        $planResult = $this->wbFinancialReportSyncPlanner->planRangeLimited(
            $from,
            $to,
            FinancialReportSyncMode::MANUAL,
            self::WB_MANUAL_RANGE_LIMIT_DAYS,
            $command->companyId,
            $command->connectionId,
            true,
        );

        return $planResult->dispatchedCount;
    }

    private function normalizeWbBusinessDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $businessDate = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date->format('Y-m-d'),
            new \DateTimeZone('Europe/Moscow'),
        );

        if (!$businessDate instanceof \DateTimeImmutable) {
            throw new \DomainException('Неверный формат даты WB');
        }

        return $businessDate;
    }
}
