<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\RetryMarketplaceRawProcessingCommand;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Повторно запускает failed processing run.
 *
 * Сбрасывает все FAILED шаги в PENDING, run — в RUNNING.
 * COMPLETED шаги не трогаются — дублирование данных недопустимо.
 * Dispatch выполняется только для сброшенных шагов.
 */
final class RetryMarketplaceRawProcessingAction
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function __invoke(RetryMarketplaceRawProcessingCommand $command): void
    {
        $run = $this->runRepository->findByIdAndCompany($command->processingRunId, $command->companyId);

        if ($run === null) {
            throw new \DomainException('Processing run not found.');
        }

        $stepRuns = $this->stepRunRepository->findByRunId($command->companyId, $command->processingRunId);

        $failedSteps = array_filter($stepRuns, static fn($s) => $s->getStatus() === PipelineStatus::FAILED);

        if (empty($failedSteps)) {
            throw new \DomainException('No failed steps found to retry.');
        }

        // Guard: только FAILED run можно ретраить (проверяется после того как known FAILED шаги)
        $run->resetForRetry();

        foreach ($failedSteps as $stepRun) {
            $stepRun->resetForRetry();
        }

        $this->em->flush();

        foreach ($failedSteps as $stepRun) {
            $this->messageBus->dispatch(new RunMarketplaceRawProcessingStepMessage(
                companyId:       $command->companyId,
                processingRunId: $command->processingRunId,
                stepRunId:       $stepRun->getId(),
            ));
        }
    }
}
