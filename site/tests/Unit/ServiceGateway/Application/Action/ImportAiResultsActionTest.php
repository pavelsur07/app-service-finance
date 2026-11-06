<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\Action\ImportAiResultsAction;
use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class ImportAiResultsActionTest extends TestCase
{
    private bool $emClosedAfterFailure = false;
    private int $resets = 0;
    /** @var list<array<string, mixed>> параметры INSERT INTO ai_usage */
    private array $usage = [];

    public function testHandsResultsToConsumersAndAcksOnlyHandledPerPage(): void
    {
        $client = new ImportPagesClient([
            new AiResultsPage([self::aiResult('t1', 'echo'), self::aiResult('t2', 'unknown_type')], '2'),
            new AiResultsPage([self::aiResult('t3', 'echo')], null),
        ]);
        $consumer = new ImportRecordingConsumer('echo');
        $logs = new TestHandler();

        $result = $this->action($client, [$consumer], $logs)();

        self::assertSame(['t1', 't3'], $consumer->taskIds);
        self::assertSame([['t1'], ['t3']], $client->acks);
        self::assertSame([null, '2'], $client->cursors);
        self::assertSame([3, 2, 1, 0, 0], [$result->received, $result->acked, $result->unhandled, $result->failed, $result->invalid]);
        self::assertTrue($logs->hasErrorThatContains('AI results without a consumer were left unacknowledged.'));
    }

    public function testAcceptedResultRecordsTokensAndCostByModelPrice(): void
    {
        $result = self::aiResult('0192f0a0-0000-7000-8000-000000000011', 'echo', ['deepseek/deepseek-flash'], 1_000_000, 500_000);
        $client = new ImportPagesClient([new AiResultsPage([$result], null)]);

        $this->action($client, [new ImportRecordingConsumer('echo')], new TestHandler(), ['deepseek/deepseek-flash' => ['input' => 24.17, 'output' => 96.67]])();

        self::assertCount(1, $this->usage);
        self::assertSame('0192f0a0-0000-7000-8000-000000000011', $this->usage[0]['task']);
        self::assertSame('deepseek/deepseek-flash', $this->usage[0]['model']);
        self::assertSame([1_000_000, 500_000], [$this->usage[0]['in'], $this->usage[0]['out']]);
        // 1 млн × 24,17 + 0,5 млн × 96,67 = 72,505 ₽
        self::assertSame('72.5050', $this->usage[0]['cost']);
        self::assertSame('2026-10-30 12:00:00', $this->usage[0]['completed']);
    }

    public function testUnknownModelPriceKeepsTokensWithoutCostAndWarnsOnce(): void
    {
        $client = new ImportPagesClient([new AiResultsPage([
            self::aiResult('0192f0a0-0000-7000-8000-000000000012', 'echo', ['kimi/k2'], 10, 5),
            self::aiResult('0192f0a0-0000-7000-8000-000000000013', 'echo', ['kimi/k2'], 10, 5),
        ], null)]);
        $logs = new TestHandler();

        $this->action($client, [new ImportRecordingConsumer('echo')], $logs, ['kimi/k2' => ['input' => null, 'output' => null]])();

        self::assertSame([null, null], array_column($this->usage, 'cost'));
        self::assertSame([10, 10], array_column($this->usage, 'in'));
        self::assertCount(1, array_filter($logs->getRecords(), static fn ($r): bool => str_contains($r->message, 'model price is unknown')));
    }

    public function testFailedConsumerRecordsNoUsage(): void
    {
        $client = new ImportPagesClient([new AiResultsPage([self::aiResult('t1', 'echo')], null)]);

        $this->action($client, [new ImportRecordingConsumer('echo', failTaskIds: ['t1'])], new TestHandler())();

        self::assertSame([], $this->usage);
    }

    public function testEmptyQueueDoesNothing(): void
    {
        $client = new ImportPagesClient([new AiResultsPage([], null)]);

        $result = $this->action($client, [], new TestHandler())();

        self::assertSame([0, 0, 0], [$result->received, $result->acked, $result->unhandled]);
        self::assertSame([], $client->acks);
    }

    public function testConsumerFailureIsIsolatedAndEntityManagerIsReset(): void
    {
        $client = new ImportPagesClient([
            new AiResultsPage([self::aiResult('t1', 'echo'), self::aiResult('t2', 'echo')], null),
        ]);
        $consumer = new ImportRecordingConsumer('echo', failTaskIds: ['t1']);
        $logs = new TestHandler();
        $this->emClosedAfterFailure = true;

        $result = $this->action($client, [$consumer], $logs)();

        self::assertSame(['t2'], $consumer->taskIds);
        self::assertSame([['t2']], $client->acks);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $this->resets);
        self::assertTrue($logs->hasErrorThatContains('AI result consumers failed'));
    }

    public function testQuarantinedResultsDoNotStarveNewerOnes(): void
    {
        // 25 страниц без потребителя (больше прежнего лимита прогона), затем обрабатываемый результат.
        $pages = [];
        for ($i = 1; $i <= 25; ++$i) {
            $pages[] = new AiResultsPage([self::aiResult('q'.$i, 'unknown_type')], (string) $i);
        }
        $pages[] = new AiResultsPage([self::aiResult('t1', 'echo')], null);
        $client = new ImportPagesClient($pages);
        $consumer = new ImportRecordingConsumer('echo');

        $result = $this->action($client, [$consumer], new TestHandler())();

        self::assertSame(['t1'], $consumer->taskIds);
        self::assertSame(25, $result->unhandled);
    }

    public function testInvalidResultsAreCountedAndLogged(): void
    {
        $client = new ImportPagesClient([new AiResultsPage([self::aiResult('t1', 'echo')], null, ['task_id=?: bad'])]);
        $logs = new TestHandler();

        $result = $this->action($client, [new ImportRecordingConsumer('echo')], $logs)();

        self::assertSame([2, 1, 1], [$result->received, $result->acked, $result->invalid]);
        self::assertTrue($logs->hasErrorThatContains('violating the contract'));
    }

    public function testCursorThatDoesNotGrowIsContractViolation(): void
    {
        $client = new ImportPagesClient([
            new AiResultsPage([self::aiResult('t1', 'echo')], '5'),
            new AiResultsPage([self::aiResult('t2', 'echo')], '5'),
        ]);

        $this->expectException(ContractViolationException::class);
        $this->action($client, [new ImportRecordingConsumer('echo')], new TestHandler())();
    }

    /**
     * @param list<AiTaskResultConsumerInterface> $consumers
     * @param array<string, array{input: float|null, output: float|null}> $prices
     */
    private function action(ImportPagesClient $client, array $consumers, TestHandler $logs, array $prices = []): ImportAiResultsAction
    {
        $open = true;
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->usage[] = $params;

            return 1;
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db);
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $func) use (&$open): mixed {
            try {
                return $func();
            } catch (\Throwable $e) {
                $open = !$this->emClosedAfterFailure;
                throw $e;
            }
        });
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $registry->method('resetManager')->willReturnCallback(function () use (&$open, $em): EntityManagerInterface {
            ++$this->resets;
            $open = true;

            return $em;
        });

        return new ImportAiResultsAction($client, $consumers, $registry, new Logger('test', [$logs]), $prices);
    }

    /** @param list<string> $models */
    private static function aiResult(string $taskId, string $taskType, array $models = ['stub/echo'], int $inputTokens = 0, int $outputTokens = 0): AiTaskResult
    {
        return new AiTaskResult(
            taskId: $taskId,
            taskType: $taskType,
            companyId: '0192f0a0-0000-7000-8000-0000000000c1',
            status: AiResultStatus::ACCEPTED,
            output: ['text' => 'a'],
            checks: [],
            confidence: 1.0,
            traceId: '0192f0a0-0000-7000-8000-0000000000f1',
            models: $models,
            promptVersion: 'echo/v1',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            errorCode: null,
            completedAt: new \DateTimeImmutable('2026-10-30T12:00:00+03:00'),
        );
    }
}

final class ImportPagesClient implements AiServiceClientInterface
{
    /** @var list<list<string>> */
    public array $acks = [];
    /** @var list<string|null> */
    public array $cursors = [];

    /**
     * @param list<AiResultsPage> $pages
     */
    public function __construct(private array $pages)
    {
    }

    public function submitTask(array $request): AiTaskAccepted
    {
        throw new \LogicException('not used');
    }

    public function fetchResults(?string $after, int $limit): AiResultsPage
    {
        $this->cursors[] = $after;

        return array_shift($this->pages) ?? new AiResultsPage([], null);
    }

    public function ack(array $taskIds): int
    {
        $this->acks[] = $taskIds;

        return \count($taskIds);
    }
}

final class ImportRecordingConsumer implements AiTaskResultConsumerInterface
{
    /** @var list<string> */
    public array $taskIds = [];

    /**
     * @param list<string> $failTaskIds
     */
    public function __construct(private readonly string $taskType, private readonly array $failTaskIds = [])
    {
    }

    public function supports(string $taskType): bool
    {
        return $this->taskType === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        if (\in_array($result->taskId, $this->failTaskIds, true)) {
            throw new \RuntimeException('consumer failed');
        }
        $this->taskIds[] = $result->taskId;
    }
}
