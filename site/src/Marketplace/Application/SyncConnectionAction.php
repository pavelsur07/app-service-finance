<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Для не-WB загружает сырой отчёт за указанный период и сохраняет его как MarketplaceRawDocument.
 * Для WB только планирует дневные задачи новой финансовой синхронизации.
 *
 * Используется из:
 *   - MarketplaceController::syncConnection()       — синхронизация за последние 7 дней
 *   - MarketplaceController::syncConnectionPeriod() — синхронизация за произвольный период
 *
 * @return int количество загруженных записей (не-WB) или запланированных задач (WB)
 */
final class SyncConnectionAction
{
    private const WB_MANUAL_RANGE_LIMIT_DAYS = 31;

    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly EntityManagerInterface $em,
        private readonly WbFinancialReportSyncPlannerInterface $wbFinancialReportSyncPlanner,
    ) {
    }

    public function __invoke(SyncConnectionCommand $command): int
    {
        $connection = $this->connectionRepository->find($command->connectionId);

        if (!$connection || (string) $connection->getCompany()->getId() !== $command->companyId) {
            throw new \DomainException('Подключение не найдено');
        }

        $connection->markSyncStarted();
        $this->em->flush();

        try {
            if (MarketplaceType::WILDBERRIES === $connection->getMarketplace()) {
                return $this->planWbManualSync($command);
            }

            $company = $connection->getCompany();
            $adapter = $this->adapterRegistry->get($connection->getMarketplace());
            $response = $adapter->fetchRawReport($company, $command->fromDate, $command->toDate);
            $recordsCount = count($response);

            $rawDoc = new MarketplaceRawDocument(
                Uuid::uuid4()->toString(),
                $company,
                $connection->getMarketplace(),
                'sales_report',
            );
            $rawDoc->setPeriodFrom($command->fromDate);
            $rawDoc->setPeriodTo($command->toDate);
            $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
            $rawDoc->setRawData($response);
            $rawDoc->setRecordsCount($recordsCount);

            $this->em->persist($rawDoc);
            $connection->markSyncSuccess();
            $this->em->flush();

            return $recordsCount;
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            throw $e;
        }
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
