<?php

declare(strict_types=1);

namespace App\Ai\Service;

use App\Ai\Entity\AiAgent;
use App\Ai\Repository\AiRunRepository;
use Psr\Log\LoggerInterface;
use Throwable;

final class AiAgentRunner
{
    public function __construct(
        private readonly AiAgentRegistry $registry,
        private readonly AiRunRepository $runRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runAgent(AiAgent $agent): void
    {
        if (!$agent->isEnabled()) {
            $this->logger->info('AI agent skipped because it is disabled', [
                'agent' => $agent->getId(),
                'company' => $agent->getCompany()->getId(),
            ]);

            return;
        }

        $implementation = $this->registry->getAgentForType($agent->getType());
        if (null === $implementation) {
            $this->logger->warning('AI agent implementation is missing', [
                'type' => $agent->getType()->value,
                'agent' => $agent->getId(),
            ]);

            return;
        }

        try {
            $implementation->run($agent);
        } catch (Throwable $exception) {
            $pendingRun = $this->runRepository->findLatestPendingRunForAgent($agent);
            if (null !== $pendingRun) {
                $pendingRun->markAsFailed($exception->getMessage());
                $this->runRepository->save($pendingRun, true);
            }

            $this->logger->error('AI agent run failed', [
                'agent' => $agent->getId(),
                'company' => $agent->getCompany()->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
