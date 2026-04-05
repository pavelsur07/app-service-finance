<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Application\Service\MarketplacePipelineAutoStarter;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Загружает сырой отчёт с маркетплейса за указанный период и сохраняет его как MarketplaceRawDocument.
 *
 * Используется из:
 *   - MarketplaceController::syncConnection()       — синхронизация за последние 7 дней
 *   - MarketplaceController::syncConnectionPeriod() — синхронизация за произвольный период
 *
 * @return int количество загруженных записей
 */
final class SyncConnectionAction
{
    public function __construct(
        private readonly MarketplaceConnectionRepository $connectionRepository,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly EntityManagerInterface $em,
        private readonly MarketplacePipelineAutoStarter $pipelineAutoStarter,
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
            $company  = $connection->getCompany();
            $adapter  = $this->adapterRegistry->get($connection->getMarketplace());
            $response     = $adapter->fetchRawReport($company, $command->fromDate, $command->toDate);
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

            // Автозапуск daily pipeline (best-effort — не прерывает import flow)
            $this->pipelineAutoStarter->tryStart($command->companyId, $rawDoc->getId());

            return $recordsCount;
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            throw $e;
        }
    }
}
