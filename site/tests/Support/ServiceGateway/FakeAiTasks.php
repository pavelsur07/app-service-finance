<?php

declare(strict_types=1);

namespace App\Tests\Support\ServiceGateway;

use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Application\DTO\AiCompanyOverview;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Ramsey\Uuid\Uuid;

/** ИИ без сети: запоминает постановки; fail — сервис недоступен, enabled=false — выключен. */
final class FakeAiTasks implements AiTaskFacadeInterface
{
    public bool $enabled = true;
    public bool $fail = false;
    public bool $budgetExceeded = false;
    /** @var list<array{taskId: string, companyId: string, taskType: string, payload: array<string, mixed>, key: string}> */
    public array $requests = [];

    public function requestTask(string $companyId, string $taskType, array $payload, string $idempotencyKey, ?string $requestedByUserId = null): ?string
    {
        if ($this->fail) {
            throw new \RuntimeException('service down');
        }
        if (!$this->enabled) {
            return null;
        }
        $taskId = $this->taskIdFor($companyId, $taskType, $idempotencyKey);
        $this->requests[] = ['taskId' => $taskId, 'companyId' => $companyId, 'taskType' => $taskType, 'payload' => $payload, 'key' => $idempotencyKey];

        return $taskId;
    }

    public function taskIdFor(string $companyId, string $taskType, string $idempotencyKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $companyId.$taskType.$idempotencyKey)->toString();
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        return $this->enabled;
    }

    public function enabledCompanyIds(array $companyIds): array
    {
        return $companyIds;
    }

    public function connectedCompanyIds(): array
    {
        return [];
    }

    public function isServiceEnabled(): bool
    {
        return $this->enabled;
    }

    public function grantedCompanyIds(array $companyIds): array
    {
        return $companyIds;
    }

    public function companyOverview(string $companyId, int $runs = 50): AiCompanyOverview
    {
        throw new \LogicException('not used');
    }

    public function exhaustedCompanyIds(array $companyIds): array
    {
        return [];
    }

    public function budgetFor(string $companyId): AiCompanyBudget
    {
        return new AiCompanyBudget(500.0, $this->budgetExceeded ? 500.0 : 0.0, 5000.0, 0.0, 30);
    }

    public function recordRun(string $companyId, string $taskType, int $requested = 0, int $tasks = 0, int $redispatched = 0, int $expired = 0, ?string $skippedReason = null, ?string $error = null): void
    {
    }
}
