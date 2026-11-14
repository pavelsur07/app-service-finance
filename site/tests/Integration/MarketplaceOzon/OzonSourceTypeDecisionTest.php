<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceOzon;

use App\MarketplaceOzon\Application\Action\ConfirmOzonSourceTypeAction;
use App\MarketplaceOzon\Application\Action\RequestOzonSourceTypeDraftsAction;
use App\MarketplaceOzon\Application\Service\OzonSourceTypeDraftConsumer;
use App\MarketplaceOzon\Exception\OzonSourceTypeNotConfirmableException;
use App\MarketplaceOzon\Infrastructure\Query\OzonTranslationStore;
use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Application\DTO\AiCompanyOverview;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class OzonSourceTypeDecisionTest extends IntegrationTestCase
{
    private OzonTranslationStore $store;
    private FakeAiTasks $ai;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new OzonTranslationStore($this->connection, new MockClock('2026-11-14 10:00:00'));
        $this->ai = new FakeAiTasks();
        $this->companyId = Uuid::uuid7()->toString();
        // код встретился в переводе дня: pending + карантин
        $this->store->resolveKinds(['ozon:service:12'], $this->companyId, '2026-11-10');
        $this->connection->executeStatement(
            "INSERT INTO moz_quarantine (company_id, connection_ref, operation_date, source_type, amount_minor, currency, row_count, source_refs, quarantined_at)
             VALUES (?, 'cabinet-1', '2026-11-10', 'ozon:service:12', -3000, 'RUB', 1, '[]', now())",
            [$this->companyId],
        );
    }

    public function testDraftIsRequestedOnceAndStoredFromResult(): void
    {
        self::assertSame(1, $this->request());
        self::assertSame(0, $this->request(), 'черновик уже запрошен');

        $sent = $this->ai->requests[0];
        self::assertSame([RequestOzonSourceTypeDraftsAction::TASK_TYPE, 'ozon:service:12', $this->companyId], [$sent['taskType'], $sent['key'], $sent['companyId']]);
        self::assertSame(['ozon', 'ozon:service:12', 'Кросс-докинг', 'debit'], [$sent['payload']['channel'], $sent['payload']['source_type'], $sent['payload']['name'], $sent['payload']['sign']]);
        self::assertContains(['source_type' => 'ozon:service:32', 'name' => 'Логистика', 'kind' => 'logistics_to'], $sent['payload']['examples']);

        (new OzonSourceTypeDraftConsumer($this->store))->consume($this->aiResult($sent['taskId'], ['kind' => 'logistics_to', 'new_kind' => null, 'title' => 'Кросс-докинг', 'reason' => 'логистика до склада', 'confidence' => 0.7]));

        $draft = $this->store->pendingForReview()[0]['ai_draft'];
        self::assertSame(['needs_review', 'logistics_to', 'логистика до склада'], [$draft['status'] ?? null, $draft['kind'] ?? null, $draft['reason'] ?? null]);
    }

    public function testDraftGoesToAnotherQuarantinedCompanyWhenFirstHasNoAi(): void
    {
        $other = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO moz_quarantine (company_id, connection_ref, operation_date, source_type, amount_minor, currency, row_count, source_refs, quarantined_at)
             VALUES (?, 'cabinet-2', '2026-11-11', 'ozon:service:12', -1000, 'RUB', 1, '[]', now())",
            [$other],
        );
        $this->ai->enabledOnly = [$other];

        self::assertSame(1, $this->request());
        self::assertSame($other, $this->ai->requests[0]['companyId']);
    }

    public function testServiceFailureIsRetriedAndDisabledAiLeavesNoDraft(): void
    {
        $this->ai->fail = true;
        self::assertSame(0, $this->request());
        self::assertNull($this->store->pendingForReview()[0]['ai_draft']);

        $this->ai->fail = false;
        $this->ai->enabled = false;
        self::assertSame(0, $this->request());
        self::assertSame('unavailable', $this->store->pendingForReview()[0]['ai_draft']['status'] ?? null);
    }

    public function testConfirmDecidesOnceAndRejectsUnknownKind(): void
    {
        $confirm = new ConfirmOzonSourceTypeAction($this->store);
        $userId = Uuid::uuid7()->toString();

        try {
            $confirm('ozon:service:12', 'teleport', 'Кросс-докинг', $userId);
            self::fail('unknown kind must be rejected');
        } catch (OzonSourceTypeNotConfirmableException) {
        }

        $confirm('ozon:service:12', 'logistics_to', ' Кросс-докинг Ozon ', $userId);
        self::assertSame(['logistics_to', 'confirmed', 'Кросс-докинг Ozon', $userId], array_values((array) $this->connection->fetchAssociative(
            "SELECT kind, status, name, decided_by FROM moz_source_type WHERE source_type = 'ozon:service:12'",
        )));
        self::assertSame([], $this->store->pendingForReview());
        self::assertArrayHasKey(OzonTranslationStore::dayKey($this->companyId, 'cabinet-1', '2026-11-10'), $this->store->daysWithDecidedQuarantine());

        $this->expectException(OzonSourceTypeNotConfirmableException::class);
        $confirm('ozon:service:12', 'storage', 'x', $userId);
    }

    private function request(): int
    {
        return (new RequestOzonSourceTypeDraftsAction($this->store, $this->ai, new MockClock('2026-11-14 10:00:00'), new NullLogger()))();
    }

    /**
     * @param array<string, mixed> $output
     */
    private function aiResult(string $taskId, array $output): AiTaskResult
    {
        return new AiTaskResult($taskId, RequestOzonSourceTypeDraftsAction::TASK_TYPE, $this->companyId, AiResultStatus::NEEDS_REVIEW, $output, [], 0.7, 'trace', ['deepseek/deepseek-flash'], 'v1', 10, 5, null, new \DateTimeImmutable('2026-11-14 10:01:00'));
    }
}

final class FakeAiTasks implements AiTaskFacadeInterface
{
    public bool $enabled = true;
    public bool $fail = false;
    /** @var list<string>|null компании с ИИ; null — все */
    public ?array $enabledOnly = null;
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
        return null === $this->enabledOnly ? $companyIds : array_values(array_intersect($companyIds, $this->enabledOnly));
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
        throw new \LogicException('not used');
    }

    public function recordRun(string $companyId, string $taskType, int $requested = 0, int $tasks = 0, int $redispatched = 0, int $expired = 0, ?string $skippedReason = null, ?string $error = null): void
    {
    }
}
