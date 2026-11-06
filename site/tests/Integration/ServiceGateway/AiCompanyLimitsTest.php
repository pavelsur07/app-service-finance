<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\SetAiCompanyAccessAction;
use App\ServiceGateway\Application\Action\UpdateAiCompanyLimitsAction;
use App\ServiceGateway\Application\Service\AiBudget;
use App\ServiceGateway\Exception\InvalidAiCompanyLimitsException;
use App\ServiceGateway\Infrastructure\Query\AiUsageQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class AiCompanyLimitsTest extends IntegrationTestCase
{
    private const COMPANY = '0192f0a0-0000-7000-8000-0000000000c1';
    private const OTHER = '0192f0a0-0000-7000-8000-0000000000c2';
    private const ADMIN = '0192f0a0-0000-7000-8000-0000000000a1';

    public function testEnableStoresLimitAndLookbackAndUpdateChangesThem(): void
    {
        $this->enable(self::COMPANY, '250,50', 30);
        self::assertSame(['monthly_limit_rub' => '250.50', 'lookback_days' => 30], $this->settings(self::COMPANY));

        $this->update()(self::COMPANY, '1000', 7, self::ADMIN);
        self::assertSame(['monthly_limit_rub' => '1000.00', 'lookback_days' => 7], $this->settings(self::COMPANY));
    }

    public function testInvalidLimitsAreRejected(): void
    {
        $this->enable(self::COMPANY);

        foreach ([['0', 90], ['-5', 90], ['abc', 90], ['100', 14]] as [$limit, $lookback]) {
            try {
                $this->update()(self::COMPANY, $limit, $lookback, self::ADMIN);
                self::fail(sprintf('limit %s, lookback %d must be rejected', $limit, $lookback));
            } catch (InvalidAiCompanyLimitsException) {
            }
        }
        self::assertSame(['monthly_limit_rub' => '500.00', 'lookback_days' => 90], $this->settings(self::COMPANY));
    }

    public function testUpdateOfNotConnectedCompanyIsRejected(): void
    {
        $this->expectException(InvalidAiCompanyLimitsException::class);
        $this->update()(self::COMPANY, '100', 90, self::ADMIN);
    }

    public function testBudgetComparesCompanyAndTotalSpendOfCurrentMonth(): void
    {
        $this->enable(self::COMPANY, '10');
        $this->enable(self::OTHER, '1000');
        $this->spend(self::COMPANY, '9.9900', '2026-11-03 10:00:00');
        $this->spend(self::COMPANY, '50.0000', '2026-10-31 23:59:00');
        $this->spend(self::OTHER, '30.0000', '2026-11-04 10:00:00');

        $budget = $this->budget('2026-11-20 12:00:00', 40.0);
        $company = $budget->forCompany(self::COMPANY);
        self::assertFalse($company->companyExceeded());
        // Общий: 9,99 + 30 (октябрьские 50 не входят) — меньше 40.
        self::assertFalse($company->totalExceeded());

        $this->spend(self::COMPANY, '0.0100', '2026-11-05 10:00:00');
        $after = $budget->forCompany(self::COMPANY);
        self::assertTrue($after->companyExceeded());
        self::assertTrue($after->totalExceeded());
        self::assertFalse($budget->forCompany(self::OTHER)->companyExceeded(), 'у другой компании свой лимит, но общий исчерпан');
        self::assertTrue($budget->forCompany(self::OTHER)->exceeded());
        // Новый месяц — расход обнуляется.
        self::assertFalse($this->budget('2026-12-01 00:10:00', 40.0)->forCompany(self::COMPANY)->exceeded());
    }

    private function enable(string $companyId, string $limit = '500', int $lookback = 90): void
    {
        (new SetAiCompanyAccessAction($this->connection, new MockClock(), new NullLogger()))($companyId, true, self::ADMIN, $limit, $lookback);
    }

    private function update(): UpdateAiCompanyLimitsAction
    {
        return new UpdateAiCompanyLimitsAction($this->connection, new NullLogger());
    }

    private function budget(string $now, float $totalLimit): AiBudget
    {
        return new AiBudget($this->connection, new AiUsageQuery($this->connection, new MockClock(new \DateTimeImmutable($now))), $totalLimit);
    }

    /** @return array{monthly_limit_rub: string, lookback_days: int} */
    private function settings(string $companyId): array
    {
        $row = $this->connection->fetchAssociative('SELECT monthly_limit_rub, lookback_days FROM ai_company_access WHERE company_id = ?', [$companyId]);
        \assert(false !== $row);

        return ['monthly_limit_rub' => (string) $row['monthly_limit_rub'], 'lookback_days' => (int) $row['lookback_days']];
    }

    private function spend(string $companyId, string $cost, string $at): void
    {
        $this->connection->insert('ai_usage', [
            'id' => Uuid::uuid7()->toString(),
            'task_id' => Uuid::uuid7()->toString(),
            'company_id' => $companyId,
            'task_type' => 'categorize_transactions',
            'status' => 'accepted',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_rub' => $cost,
            'completed_at' => $at,
        ]);
    }
}
