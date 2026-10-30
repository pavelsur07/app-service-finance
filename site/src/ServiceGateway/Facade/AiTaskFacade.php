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
    /** Пространство имён UUIDv5 для task_id: один ключ повтора — один task_id. */
    private const TASK_ID_NAMESPACE = '0192f0a0-5a1d-7e3c-8a5e-6f0d1c2b3a49';

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
        // Канонический вид: сервис нормализует UUID, и `ABC…`/`abc…` должны дать один task_id.
        $companyId = strtolower($companyId);
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

        // Повтор с тем же ключом возвращает тот же task_id, под которым придёт результат.
        $taskId = $this->taskIdFor($companyId, $taskType, $idempotencyKey);
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

    public function taskIdFor(string $companyId, string $taskType, string $idempotencyKey): string
    {
        Assert::uuid($companyId);

        // Канонический вид: сервис нормализует UUID, и `ABC…`/`abc…` должны дать один task_id.
        return Uuid::uuid5(self::TASK_ID_NAMESPACE, strtolower($companyId)."\n".$taskType."\n".$idempotencyKey)->toString();
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        return $this->access->isEnabledForCompany($companyId);
    }
}
