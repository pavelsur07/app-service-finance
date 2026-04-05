<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\StartMarketplaceRawProcessingCommand;
use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly StartMarketplaceRawProcessingAction $startProcessingAction,
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly LoggerInterface $logger,
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
            $this->tryAutoStartPipeline($command->companyId, $rawDoc->getId());

            return $recordsCount;
        } catch (\Exception $e) {
            $connection->markSyncFailed($e->getMessage());
            $this->em->flush();

            throw $e;
        }
    }

    /**
     * Запускает daily pipeline для сохранённого raw document.
     * Best-effort: ошибки логируются и не прерывают import flow.
     * Дедупликация: если для документа уже есть активный (не-terminal) run — пропустить.
     */
    private function tryAutoStartPipeline(string $companyId, string $rawDocId): void
    {
        $existingRun = $this->runRepository->findLatestByRawDocument($companyId, $rawDocId);
        if ($existingRun !== null && !$existingRun->getStatus()->isTerminal()) {
            $this->logger->info('[AutoStart] Active run already exists, skipping', [
                'raw_doc_id' => $rawDocId,
                'run_id'     => $existingRun->getId(),
                'status'     => $existingRun->getStatus()->value,
            ]);

            return;
        }

        try {
            ($this->startProcessingAction)(new StartMarketplaceRawProcessingCommand(
                $companyId,
                $rawDocId,
                PipelineTrigger::AUTO,
            ));

            $this->logger->info('[AutoStart] Daily pipeline started', [
                'company_id' => $companyId,
                'raw_doc_id' => $rawDocId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[AutoStart] Failed to start daily pipeline', [
                'company_id' => $companyId,
                'raw_doc_id' => $rawDocId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
