<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\ServiceGateway\Application\Action\ImportAiResultsAction;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Infrastructure\Client\AiServiceClient;

final readonly class AiTaskResultFacade implements AiTaskResultFacadeInterface
{
    public function __construct(private AiServiceClient $client, private ImportAiResultsAction $importer, private AiTaskFacadeInterface $access)
    {
    }

    public function refreshTask(string $companyId, string $taskId): bool
    {
        if (!$this->access->isEnabledForCompany($companyId)) {
            return false;
        }
        $page = $this->client->fetchTaskResults($companyId, $taskId);
        if ([] !== $page->invalid || \count($page->items) > 1) {
            throw ContractViolationException::at('ai-parse-payment-message/v1', '$', 'invalid single-task response');
        }
        foreach ($page->items as $result) {
            if (strtolower($result->companyId) !== strtolower($companyId) || strtolower($result->taskId) !== strtolower($taskId)) {
                throw ContractViolationException::at('ai-parse-payment-message/v1', '$', 'task or company mismatch');
            }

            return $this->importer->importResult($result);
        }

        return false;
    }
}
