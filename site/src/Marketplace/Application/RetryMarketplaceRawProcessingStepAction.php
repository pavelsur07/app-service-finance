<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\Command\RetryMarketplaceRawProcessingStepCommand;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\RunMarketplaceRawProcessingStepMessage;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Повторно запускает один failed шаг processing run.
 *
 * Сбрасывает FAILED шаг в PENDING.
 * Если run был FAILED — сбрасывает его в RUNNING.
 */
final class RetryMarketplaceRawProcessingStepAction
{
    public function __construct(
        private readonly MarketplaceRawProcessingRunRepository $runRepository,
        private readonly MarketplaceRawProcessingStepRunRepository $stepRunRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function __invoke(RetryMarketplaceRawProcessingStepCommand $command): void
    {
        $run = $this->runRepository->findByIdAndCompany($command->processingRunId, $command->companyId);

        if ($run === null) {
            throw new \DomainException('Processing run not found.');
        }

        $stepRun = $this->stepRunRepository->findByIdAndCompany($command->stepRunId, $command->companyId);

        if ($stepRun === null) {
            throw new \DomainException('Step run not found.');
        }

        if ($stepRun->getProcessingRunId() !== $command->processingRunId) {
            throw new \DomainException('Step run does not belong to the given processing run.');
        }

        // Если run был FAILED — перевести в RUNNING чтобы финализатор мог завершить run
        if ($run->getStatus() === PipelineStatus::FAILED) {
            $run->resetForRetry();
        }

        // Run должен быть RUNNING (изначально или после сброса выше)
        if ($run->getStatus() !== PipelineStatus::RUNNING) {
            throw new \DomainException('Cannot retry a step for a run that is not in RUNNING or FAILED state.');
        }

        // Guard: только FAILED шаг можно ретраить
        $stepRun->resetForRetry();

        $this->em->flush();

        $this->messageBus->dispatch(new RunMarketplaceRawProcessingStepMessage(
            companyId:       $command->companyId,
            processingRunId: $command->processingRunId,
            stepRunId:       $command->stepRunId,
        ));
    }
}
