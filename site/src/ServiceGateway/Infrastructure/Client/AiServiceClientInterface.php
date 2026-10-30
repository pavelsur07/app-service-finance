<?php

declare(strict_types=1);

namespace App\ServiceGateway\Infrastructure\Client;

use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Exception\AiTaskRejectedException;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Exception\ServiceUnavailableException;

/**
 * API задач ai-service (контракт `ai-task/v1`) по внутренней сети.
 */
interface AiServiceClientInterface
{
    /**
     * @param array<string, mixed> $request тело `task_request`
     *
     * @throws ServiceUnavailableException транспорт, таймаут, 401, 5xx — повторяемо
     * @throws AiTaskRejectedException 4xx от сервиса — повтор не поможет
     * @throws ContractViolationException
     */
    public function submitTask(array $request): AiTaskAccepted;

    /**
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function fetchResults(?string $after, int $limit): AiResultsPage;

    /**
     * @param list<string> $taskIds
     *
     * @throws ServiceUnavailableException
     * @throws ContractViolationException
     */
    public function ack(array $taskIds): int;
}
