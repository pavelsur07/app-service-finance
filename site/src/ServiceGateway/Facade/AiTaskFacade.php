<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Message\SubmitAiTaskMessage;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final readonly class AiTaskFacade implements AiTaskFacadeInterface
{
    public function __construct(
        private AiServiceAccess $access,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function requestTask(
        string $companyId,
        string $taskType,
        array $payload,
        string $idempotencyKey,
        ?string $requestedByUserId = null,
    ): ?string {
        Assert::uuid($companyId);
        if (null !== $requestedByUserId) {
            Assert::uuid($requestedByUserId);
        }
        if (!$this->access->isEnabledForCompany($companyId)) {
            $this->logger->info('AI task skipped: ai-service is disabled for the company.', [
                'companyId' => $companyId,
                'taskType' => $taskType,
            ]);

            return null;
        }

        $taskId = Uuid::uuid7()->toString();
        $this->bus->dispatch(new SubmitAiTaskMessage(
            taskId: $taskId,
            taskType: $taskType,
            companyId: $companyId,
            idempotencyKey: $idempotencyKey,
            requestedBy: $requestedByUserId ?? 'system',
            traceId: Uuid::uuid7()->toString(),
            createdAt: $this->clock->now()->format(\DATE_RFC3339),
            payload: $payload,
        ));
        $this->logger->info('AI task requested.', ['companyId' => $companyId, 'taskType' => $taskType, 'taskId' => $taskId]);

        return $taskId;
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        return $this->access->isEnabledForCompany($companyId);
    }
}
