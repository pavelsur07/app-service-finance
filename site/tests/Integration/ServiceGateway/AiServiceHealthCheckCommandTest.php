<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Service\AiBudget;
use App\ServiceGateway\Application\Service\AiRunJournal;
use App\ServiceGateway\Application\Service\AiServiceAccess;
use App\ServiceGateway\Command\AiServiceHealthCheckCommand;
use App\ServiceGateway\Infrastructure\Query\AiUsageQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiServiceHealthCheckCommandTest extends IntegrationTestCase
{
    private const NOW = '2026-11-06 12:00:00';
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';

    private TestHandler $logs;

    public function testDisabledServiceIsSilent(): void
    {
        $this->connect(self::COMPANY, '-10 hours');

        self::assertSame(Command::SUCCESS, $this->check(enabled: false));
        self::assertSame([], $this->logs->getRecords());
    }

    public function testCompanyWithoutRunForThreeHoursIsAnError(): void
    {
        $this->connect(self::COMPANY, '-10 hours');
        $this->connect(self::OTHER, '-10 hours');
        $this->ran(self::COMPANY, '-4 hours');
        $this->ran(self::OTHER, '-30 minutes');

        self::assertSame(Command::FAILURE, $this->check());
        self::assertTrue($this->logs->hasErrorThatContains('connected companies without an AI run'));
        self::assertSame([self::COMPANY], $this->logs->getRecords()[0]->context['companyIds']);
    }

    public function testRecentlyConnectedCompanyWithoutRunsIsNotSilentYet(): void
    {
        $this->connect(self::COMPANY, '-1 hour');

        self::assertSame(Command::SUCCESS, $this->check());
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testMostlyFailedResultsAreAnErrorOnlyFromTenResults(): void
    {
        $this->connect(self::COMPANY, '-10 hours');
        $this->ran(self::COMPANY, '-10 minutes');
        for ($i = 0; $i < 9; ++$i) {
            $this->usageRow('failed', '-1 hour');
        }
        self::assertSame(Command::SUCCESS, $this->check(), '9 результатов — мало для вывода');

        $this->usageRow('accepted', '-1 hour');
        self::assertSame(Command::FAILURE, $this->check());
        self::assertTrue($this->logs->hasErrorThatContains('most AI results failed'));
    }

    public function testExhaustedBudgetIsWarningNotError(): void
    {
        $this->connect(self::COMPANY, '-10 hours');
        $this->ran(self::COMPANY, '-10 minutes');
        $this->connection->update('ai_company_access', ['monthly_limit_rub' => '1.00'], ['company_id' => self::COMPANY]);
        $this->usageRow('accepted', '-1 hour', '1.5000');

        self::assertSame(Command::SUCCESS, $this->check());
        self::assertTrue($this->logs->hasWarningThatContains('monthly AI budget is exhausted'));
    }

    public function testRunsOlderThanNinetyDaysArePurged(): void
    {
        $this->ran(self::COMPANY, '-91 days');
        $this->ran(self::COMPANY, '-89 days');

        $this->check();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM ai_run'));
    }

    private function check(bool $enabled = true): int
    {
        $this->logs = new TestHandler();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        $usage = new AiUsageQuery($this->connection, $clock);

        return (new CommandTester(new AiServiceHealthCheckCommand(
            new AiServiceAccess($enabled, 'http://ai', 'token', $this->connection),
            new AiRunJournal($this->connection, $clock),
            $usage,
            new AiBudget($this->connection, $usage, 5000.0),
            $clock,
            new Logger('test', [$this->logs]),
        )))->execute([]);
    }

    private function connect(string $companyId, string $ago): void
    {
        $this->connection->insert('ai_company_access', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => $companyId,
            'enabled_at' => $this->at($ago),
        ]);
    }

    private function ran(string $companyId, string $ago): void
    {
        $this->connection->insert('ai_run', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => $companyId,
            'task_type' => 'categorize_transactions',
            'ran_at' => $this->at($ago),
            'requested' => 0,
            'tasks' => 0,
            'redispatched' => 0,
            'expired' => 0,
        ]);
    }

    private function usageRow(string $status, string $ago, ?string $costRub = null): void
    {
        $this->connection->insert('ai_usage', [
            'id' => Uuid::uuid7()->toString(),
            'task_id' => Uuid::uuid7()->toString(),
            'company_id' => self::COMPANY,
            'task_type' => 'categorize_transactions',
            'status' => $status,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_rub' => $costRub,
            'completed_at' => $this->at($ago),
        ]);
    }

    private function at(string $ago): string
    {
        return (new \DateTimeImmutable(self::NOW))->modify($ago)->format('Y-m-d H:i:s');
    }
}
