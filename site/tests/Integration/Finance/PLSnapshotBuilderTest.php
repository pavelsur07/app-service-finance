<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Finance\Application\Service\PLSnapshotBuilder;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

/**
 * Месячный снимок ОПиУ пересобирается заменой строк месяца без flush(): повторная сборка не
 * множит строку «без статьи», статья, ушедшая из регистра, уходит из снимка, чужой UnitOfWork не
 * сохраняется.
 */
final class PLSnapshotBuilderTest extends IntegrationTestCase
{
    private Company $company;
    private ProjectDirection $project;
    private PLCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4()->toString().'@test.com')
            ->withPasswordHash('hash')
            ->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();
        $this->company->setName('Snapshot company');
        $this->project = new ProjectDirection(Uuid::uuid4()->toString(), $this->company, 'Основной');
        $this->category = (new PLCategory(Uuid::uuid4()->toString(), $this->company))
            ->setName('Продажи')
            ->setFlow(PLFlow::INCOME);

        foreach ([$user, $this->company, $this->project, $this->category] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testRebuildReplacesMonthRows(): void
    {
        $this->registerRow($this->category->getId(), '2026-04-10', '100.00');
        $this->registerRow(null, '2026-04-11', '30.00');

        $builder = self::getContainer()->get(PLSnapshotBuilder::class);
        $builder->rebuildMonthly($this->company, '2026-04');
        $builder->rebuildMonthly($this->company, '2026-04');

        self::assertSame([
            '(none)' => '30.00',
            (string) $this->category->getId() => '100.00',
        ], $this->snapshotIncome());
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pl_monthly_snapshots WHERE company_id = :company',
            ['company' => $this->company->getId()],
        ));

        $this->connection->executeStatement(
            'DELETE FROM pl_daily_totals WHERE company_id = :company AND pl_category_id IS NOT NULL',
            ['company' => $this->company->getId()],
        );
        $builder->rebuildMonthly($this->company, '2026-04');

        self::assertSame(['(none)' => '30.00'], $this->snapshotIncome());
    }

    public function testRebuildDoesNotFlushCallerUnitOfWork(): void
    {
        $this->company->setName('pending, not flushed');

        self::getContainer()->get(PLSnapshotBuilder::class)->rebuildMonthly($this->company, '2026-04');

        self::assertSame('Snapshot company', $this->connection->fetchOne(
            'SELECT name FROM companies WHERE id = :id',
            ['id' => $this->company->getId()],
        ));
    }

    public function testRebuildWaitsForConcurrentRebuildOfSameMonth(): void
    {
        $this->registerRow(null, '2026-04-11', '30.00');

        // Параллельная пересборка того же месяца держит блокировку в своей сессии.
        $other = DriverManager::getConnection($this->connection->getParams());
        self::assertTrue((bool) $other->fetchOne(
            "SELECT pg_try_advisory_lock(hashtext('pl_monthly_snapshot'), hashtext(:key))",
            ['key' => $this->company->getId().':2026-04'],
        ));
        $this->connection->executeStatement("SET LOCAL lock_timeout = '200ms'");

        try {
            self::getContainer()->get(PLSnapshotBuilder::class)->rebuildMonthly($this->company, '2026-04');
            self::fail('Пересборка должна ждать параллельную пересборку того же месяца.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState(), 'Ожидалось ожидание блокировки (lock_timeout).');
        } finally {
            $other->close();
        }

        self::assertSame([], $this->snapshotIncome());
    }

    private function registerRow(?string $categoryId, string $day, string $income): void
    {
        $this->connection->insert('pl_daily_totals', [
            'id' => Uuid::uuid4()->toString(),
            'company_id' => $this->company->getId(),
            'pl_category_id' => $categoryId,
            'project_direction_id' => $this->project->getId(),
            'date' => $day,
            'amount_income' => $income,
            'amount_expense' => '0.00',
            'created_at' => '2026-04-30 00:00:00',
            'updated_at' => '2026-04-30 00:00:00',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function snapshotIncome(): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT COALESCE(pl_category_id::text, '(none)'), amount_income::text FROM pl_monthly_snapshots WHERE company_id = :company AND period = '2026-04' ORDER BY pl_category_id NULLS FIRST",
            ['company' => $this->company->getId()],
        );

        return $rows;
    }
}
