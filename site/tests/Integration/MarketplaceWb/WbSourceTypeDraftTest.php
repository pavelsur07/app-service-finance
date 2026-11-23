<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceWb;

use App\MarketplaceWb\Application\Action\RequestWbSourceTypeDraftsAction;
use App\MarketplaceWb\Application\Service\WbSourceTypeDraftConsumer;
use App\MarketplaceWb\Infrastructure\Query\WbTranslationStore;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class WbSourceTypeDraftTest extends IntegrationTestCase
{
    private const CODE = 'wb:payout:добровольная компенсация при возврате';

    private WbTranslationStore $store;
    /** @var list<array{taskType: string, payload: array<string, mixed>, key: string, companyId: string}> */
    private array $requests = [];
    private bool $enabled = true;
    private bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new WbTranslationStore($this->connection, new MockClock('2026-11-15 10:00:00'));
        $companyId = Uuid::uuid7()->toString();
        $this->store->resolveKinds([self::CODE], $companyId, '2026-11-10');
        $this->connection->executeStatement(
            "INSERT INTO mwb_quarantine (company_id, connection_ref, operation_date, source_type, amount_minor, currency, row_count, source_refs, quarantined_at)
             VALUES (?, 'cabinet-wb', '2026-11-10', ?, 353274, 'RUB', 1, '[]', now())",
            [$companyId, self::CODE],
        );
    }

    public function testDraftIsRequestedOnceWithWbChannelAndStoredFromResult(): void
    {
        self::assertSame(1, $this->request());
        self::assertSame(0, $this->request(), 'черновик уже запрошен');

        $sent = $this->requests[0];
        self::assertSame([RequestWbSourceTypeDraftsAction::TASK_TYPE, self::CODE, AiTaskFacadeInterface::PLATFORM_COMPANY_ID], [$sent['taskType'], $sent['key'], $sent['companyId']]);
        self::assertSame(['wb', self::CODE, 'добровольная компенсация при возврате', 'credit'], [$sent['payload']['channel'], $sent['payload']['source_type'], $sent['payload']['name'], $sent['payload']['sign']]);
        self::assertContains(['source_type' => 'wb:deduction:Предоставление услуг по подписке «Джем»', 'name' => 'Подписка «Джем»', 'kind' => 'subscription'], $sent['payload']['examples']);
        self::assertSame('requested', $this->store->pendingForReview()[0]['ai_draft']['status'] ?? null);

        $consumer = new WbSourceTypeDraftConsumer($this->store);
        self::assertTrue($consumer->supports(RequestWbSourceTypeDraftsAction::TASK_TYPE));
        // чужая задача того же типа (код Ozon) не трогает строки WB
        $consumer->consume($this->aiResult(Uuid::uuid7()->toString(), ['kind' => 'storage']));
        $consumer->consume($this->aiResult($this->taskId(), ['kind' => 'compensation', 'new_kind' => null, 'title' => 'Компенсация при возврате', 'reason' => 'выплата WB продавцу']));

        $draft = $this->store->pendingForReview()[0]['ai_draft'];
        self::assertSame(['needs_review', 'compensation', 'Компенсация при возврате'], [$draft['status'] ?? null, $draft['kind'] ?? null, $draft['title'] ?? null]);
    }

    public function testServiceFailureOrSwitchedOffServiceIsRetried(): void
    {
        $this->fail = true;
        self::assertSame(0, $this->request());
        self::assertNull($this->store->pendingForReview()[0]['ai_draft']);

        $this->fail = false;
        $this->enabled = false;
        self::assertSame(0, $this->request());
        self::assertNull($this->store->pendingForReview()[0]['ai_draft']);

        $this->enabled = true;
        self::assertSame(1, $this->request());
    }

    public function testRequestWithoutAnswerForAnHourIsRepeated(): void
    {
        self::assertSame(1, $this->request());
        $this->store = new WbTranslationStore($this->connection, new MockClock('2026-11-15 10:30:00'));
        self::assertSame(0, $this->request(), 'полчаса — ждём ответ');

        $this->store = new WbTranslationStore($this->connection, new MockClock('2026-11-15 11:01:00'));
        self::assertSame(1, $this->request(), 'постановка упала окончательно — запрос повторяется с тем же ключом');
        self::assertSame($this->requests[0]['key'], $this->requests[1]['key']);
    }

    private function request(): int
    {
        $ai = $this->createStub(AiTaskFacadeInterface::class);
        $ai->method('isServiceEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $ai->method('taskIdFor')->willReturnCallback(fn (string $c, string $t, string $k): string => $this->taskId($k));
        $ai->method('requestTask')->willReturnCallback(function (string $companyId, string $taskType, array $payload, string $key): ?string {
            if ($this->fail) {
                throw new \RuntimeException('service down');
            }
            if (!$this->enabled) {
                return null;
            }
            $this->requests[] = ['taskType' => $taskType, 'payload' => $payload, 'key' => $key, 'companyId' => $companyId];

            return $this->taskId($key);
        });

        return (new RequestWbSourceTypeDraftsAction($this->store, $ai, new MockClock('2026-11-15 10:00:00'), new NullLogger()))();
    }

    private function taskId(string $key = self::CODE): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $key)->toString();
    }

    /** @param array<string, mixed> $output */
    private function aiResult(string $taskId, array $output): AiTaskResult
    {
        return new AiTaskResult($taskId, RequestWbSourceTypeDraftsAction::TASK_TYPE, AiTaskFacadeInterface::PLATFORM_COMPANY_ID, AiResultStatus::NEEDS_REVIEW, $output, [], 0.8, 'trace', ['deepseek/deepseek-flash'], 'v1', 10, 5, null, new \DateTimeImmutable('2026-11-15 10:01:00'));
    }
}
