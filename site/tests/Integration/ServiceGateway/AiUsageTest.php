<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\ImportAiResultsAction;
use App\ServiceGateway\Application\DTO\AiResultsPage;
use App\ServiceGateway\Application\DTO\AiTaskAccepted;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use App\ServiceGateway\Infrastructure\Query\AiUsageQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class AiUsageTest extends IntegrationTestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';

    public function testRepeatedImportOfSameResultKeepsOneUsageRow(): void
    {
        $result = $this->aiResult('0192f0a0-0000-7000-8000-000000000101', self::COMPANY, '2026-11-06T10:00:00+03:00', 1_000_000);

        // Сбой ack: сервис отдаёт тот же результат во втором прогоне.
        $this->import([$result]);
        $this->import([$result]);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM ai_usage'));
        self::assertSame('24.1700', $this->connection->fetchOne('SELECT cost_rub FROM ai_usage'));
    }

    public function testSpentThisMonthCountsCurrentMoscowMonthOnly(): void
    {
        $this->import([
            $this->aiResult('0192f0a0-0000-7000-8000-000000000201', self::COMPANY, '2026-11-01T00:30:00+03:00', 1_000_000),
            $this->aiResult('0192f0a0-0000-7000-8000-000000000202', self::COMPANY, '2026-11-20T12:00:00+03:00', 2_000_000),
            // 31.10 23:30 МСК — прошлый месяц, хотя в UTC это тоже 31.10.
            $this->aiResult('0192f0a0-0000-7000-8000-000000000203', self::COMPANY, '2026-10-31T23:30:00+03:00', 5_000_000),
            $this->aiResult('0192f0a0-0000-7000-8000-000000000204', self::OTHER, '2026-11-10T12:00:00+03:00', 1_000_000),
        ]);
        $query = new AiUsageQuery($this->connection, new MockClock(new \DateTimeImmutable('2026-11-25T12:00:00+03:00')));

        self::assertEqualsWithDelta(72.51, $query->spentThisMonth(self::COMPANY), 0.001);
        self::assertEqualsWithDelta(96.68, $query->spentThisMonthTotal(), 0.001);
    }

    /** @param list<AiTaskResult> $results */
    private function import(array $results): void
    {
        $client = new class($results) implements AiServiceClientInterface {
            /** @param list<AiTaskResult> $results */
            public function __construct(private readonly array $results)
            {
            }

            public function submitTask(array $request): AiTaskAccepted
            {
                throw new \LogicException('not used');
            }

            public function fetchResults(?string $after, int $limit): AiResultsPage
            {
                return new AiResultsPage($this->results, null);
            }

            public function ack(array $taskIds): int
            {
                return \count($taskIds);
            }
        };
        $consumer = new class implements AiTaskResultConsumerInterface {
            public function supports(string $taskType): bool
            {
                return true;
            }

            public function consume(AiTaskResult $result): void
            {
            }
        };

        (new ImportAiResultsAction(
            $client,
            [$consumer],
            self::getContainer()->get(ManagerRegistry::class),
            new NullLogger(),
            ['deepseek/deepseek-flash' => ['input' => 24.17, 'output' => 96.67]],
        ))();
    }

    private function aiResult(string $taskId, string $companyId, string $completedAt, int $inputTokens): AiTaskResult
    {
        return new AiTaskResult(
            taskId: $taskId,
            taskType: 'categorize_transactions',
            companyId: $companyId,
            status: AiResultStatus::ACCEPTED,
            output: [],
            checks: [],
            confidence: 0.9,
            traceId: '0192f0a0-0000-7000-8000-0000000000f1',
            models: ['deepseek/deepseek-flash'],
            promptVersion: 'categorize/v2',
            inputTokens: $inputTokens,
            outputTokens: 0,
            errorCode: null,
            completedAt: new \DateTimeImmutable($completedAt),
        );
    }
}
