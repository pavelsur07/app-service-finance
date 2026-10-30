<?php

declare(strict_types=1);

namespace App\ServiceGateway\MessageHandler;

use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use App\ServiceGateway\Message\SubmitAiTaskMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * POST /internal/tasks. Без БД: сообщение несёт все идентификаторы, повтор безопасен
 * (сервис сливает тот же `Idempotency-Key`). Выключенный сервис или его недоступность —
 * ретраи Messenger, затем failed-транспорт: задача ждёт, а не теряется.
 */
#[AsMessageHandler]
final readonly class SubmitAiTaskHandler
{
    public function __construct(
        private AiServiceClientInterface $client,
        private AiServiceAccess $access,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SubmitAiTaskMessage $message): void
    {
        $context = ['taskId' => $message->taskId, 'taskType' => $message->taskType, 'companyId' => $message->companyId];
        $this->logger->info('Submit AI task started.', $context);

        if (!$this->access->isEnabledForCompany($message->companyId)) {
            $this->logger->warning('Submit AI task postponed: ai-service is disabled for the company.', $context);

            throw new RecoverableMessageHandlingException('ai-service is disabled for the company');
        }

        try {
            $accepted = $this->client->submitTask([
                'task_id' => $message->taskId,
                'task_type' => $message->taskType,
                'contract_version' => 'v1',
                'company_id' => $message->companyId,
                'idempotency_key' => $message->idempotencyKey,
                'requested_by' => $message->requestedBy,
                'created_at' => $message->createdAt,
                'trace_id' => $message->traceId,
                // Пустой payload — объект, а не JSON-массив.
                'payload' => [] === $message->payload ? new \stdClass() : $message->payload,
            ]);
        } catch (ServiceUnavailableException $e) {
            $this->logger->warning('Submit AI task will be retried: ai-service unavailable.', $context + ['error' => $e->getMessage()]);

            throw new RecoverableMessageHandlingException($e->getMessage(), 0, $e);
        } catch (AiTaskRejectedException|ContractViolationException $e) {
            $this->logger->error('Submit AI task rejected.', $context + ['error' => $e->getMessage()]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        $this->logger->info('Submit AI task finished.', $context + [
            'serviceTaskId' => $accepted->taskId,
            'duplicate' => $accepted->duplicate,
        ]);
    }
}
