<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Processor\MarketplaceRawProcessorRegistry;
use App\Marketplace\Entity\ProcessingPipelineRun;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Enum\ProcessingKind;
use App\Marketplace\Exception\PipelineAlreadyRunningException;
use App\Marketplace\Repository\ProcessingPipelineRunRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Lock\LockFactory;

final class RunPipelineAction
{
    public function __construct(
        private readonly ProcessingPipelineRunRepositoryInterface $repository,
        private readonly MarketplaceRawProcessorRegistry $processorRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {}

    public function __invoke(
        string $companyId,
        MarketplaceType $marketplace,
        PipelineTrigger $triggeredBy,
    ): ProcessingPipelineRun {
        // 1. Lock
        $lock = $this->lockFactory->createLock(
            'marketplace_pipeline_' . $companyId . '_' . $marketplace->value,
            ttl: 1800,
        );

        if (!$lock->acquire()) {
            throw new PipelineAlreadyRunningException(
                'Пайплайн уже выполняется для ' . $marketplace->getDisplayName()
            );
        }

        try {
            // 2. Найти или создать ProcessingPipelineRun
            $run = $this->repository->findByCompanyAndMarketplace($companyId, $marketplace);

            if ($run === null) {
                $run = new ProcessingPipelineRun(
                    Uuid::uuid7()->toString(),
                    $companyId,
                    $marketplace,
                    $triggeredBy,
                );
                $this->repository->save($run);
            } else {
                $run->restart($triggeredBy);
            }

            $this->entityManager->flush();

            // 3. Выполнить шаги последовательно
            $steps = [
                PipelineStep::SALES   => ProcessingKind::SALES,
                PipelineStep::RETURNS => ProcessingKind::RETURNS,
                PipelineStep::COSTS   => ProcessingKind::COSTS,
            ];

            foreach ($steps as $step => $kind) {
                try {
                    $run->markRunning($step);
                    $this->entityManager->flush();

                    $count = $this->processorRegistry->process($companyId, $marketplace, $kind);

                    $run->markStepCompleted($step, $count);
                    $this->entityManager->flush();
                } catch (\Throwable $e) {
                    $run->markFailed($step, $e->getMessage());
                    $this->entityManager->flush();

                    return $run;
                }
            }

            // 4. Завершить
            $run->markCompleted();
            $this->entityManager->flush();

            return $run;
        } finally {
            $lock->release();
        }
    }
}
