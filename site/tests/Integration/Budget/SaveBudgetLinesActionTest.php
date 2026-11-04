<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Application\SaveBudgetLinesAction;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SaveBudgetLinesActionTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f3001';

    public function testSavesCellsAsManual(): void
    {
        $this->givenVersion();

        $saved = $this->save(
            $this->cell(self::REVENUE_ID, '2027-01', '500000'),
            $this->cell(self::RENT_ID, '2027-02', '-60000.5', comment: ' склад '),
        );

        self::assertSame(2, $saved);
        self::assertSame([
            ['pl_category_id' => self::REVENUE_ID, 'month' => '2027-01-01', 'project_direction_id' => self::GENERAL_PROJECT_ID, 'responsibility_center_id' => $this->generalCenterId, 'amount' => '500000.00', 'source' => 'manual', 'comment' => null],
            ['pl_category_id' => self::RENT_ID, 'month' => '2027-02-01', 'project_direction_id' => self::GENERAL_PROJECT_ID, 'responsibility_center_id' => $this->generalCenterId, 'amount' => '-60000.50', 'source' => 'manual', 'comment' => 'склад'],
        ], $this->lines(self::VERSION_ID));
    }

    public function testRepeatedBatchGivesSameAmountsAndRowCount(): void
    {
        $this->givenVersion();
        $cells = [$this->cell(self::REVENUE_ID, '2027-01', '100'), $this->cell(self::REVENUE_ID, '2027-02', '200')];

        $this->save(...$cells);
        $first = $this->lines(self::VERSION_ID);
        $this->save(...$cells);

        self::assertSame($first, $this->lines(self::VERSION_ID));
        self::assertSame(2, $this->countRows('budget_lines'));
    }

    public function testUpdatesCellAndZeroWithoutCommentDeletesIt(): void
    {
        $this->givenVersion();
        $this->save(
            $this->cell(self::REVENUE_ID, '2027-01', '100'),
            $this->cell(self::REVENUE_ID, '2027-02', '200'),
            $this->cell(self::REVENUE_ID, '2027-03', '300'),
        );

        $this->save(
            $this->cell(self::REVENUE_ID, '2027-01', '150'),
            $this->cell(self::REVENUE_ID, '2027-02', '0'),
            $this->cell(self::REVENUE_ID, '2027-03', '0.00', comment: 'уточнить'),
        );

        self::assertSame(
            [['2027-01-01', '150.00', null], ['2027-03-01', '0.00', 'уточнить']],
            array_map(static fn (array $line): array => [$line['month'], $line['amount'], $line['comment']], $this->lines(self::VERSION_ID)),
        );
    }

    public function testSameCellTwiceInBatchKeepsLast(): void
    {
        $this->givenVersion();

        $this->save($this->cell(self::REVENUE_ID, '2027-01', '100'), $this->cell(self::REVENUE_ID, '2027-01', '250'));

        self::assertSame(['250.00'], array_column($this->lines(self::VERSION_ID), 'amount'));
    }

    public function testCellOfAnotherActivePairIsSaved(): void
    {
        $this->givenVersion();

        $this->save($this->cell(self::REVENUE_ID, '2027-01', '100', self::SHOP_PROJECT_ID, $this->salesCenterId));

        self::assertSame([self::SHOP_PROJECT_ID], array_column($this->lines(self::VERSION_ID), 'project_direction_id'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCells(): iterable
    {
        yield 'subtotal category' => ['subtotal'];
        yield 'foreign category' => ['foreignCategory'];
        yield 'archived center pair' => ['archivedPair'];
        yield 'foreign pair' => ['foreignPair'];
        yield 'pair not allowed' => ['crossPair'];
        yield 'month of another year' => ['otherYear'];
        yield 'bad month' => ['badMonth'];
        yield 'bad amount' => ['badAmount'];
        yield 'long comment' => ['longComment'];
        yield 'bad uuid' => ['badUuid'];
    }

    #[DataProvider('invalidCells')]
    public function testInvalidCellRejectsWholeBatch(string $case): void
    {
        $this->givenVersion();
        $invalid = match ($case) {
            'subtotal' => $this->cell(self::SUBTOTAL_ID, '2027-01', '1'),
            'foreignCategory' => $this->cell(self::OTHER_COMPANY_LEAF_ID, '2027-01', '1'),
            'archivedPair' => $this->cell(self::REVENUE_ID, '2027-01', '1', self::SHOP_PROJECT_ID, $this->archivedCenterId),
            'foreignPair' => $this->cell(self::REVENUE_ID, '2027-01', '1', self::OTHER_COMPANY_PROJECT_ID, $this->otherCompanyCenterId),
            'crossPair' => $this->cell(self::REVENUE_ID, '2027-01', '1', self::GENERAL_PROJECT_ID, $this->salesCenterId),
            'otherYear' => $this->cell(self::REVENUE_ID, '2028-01', '1'),
            'badMonth' => $this->cell(self::REVENUE_ID, '2027-13', '1'),
            'badAmount' => $this->cell(self::REVENUE_ID, '2027-01', '1 000,5'),
            'longComment' => $this->cell(self::REVENUE_ID, '2027-01', '1', comment: str_repeat('я', 501)),
            'badUuid' => $this->cell('not-a-uuid', '2027-01', '1'),
            default => throw new \LogicException($case),
        };

        try {
            $this->save($this->cell(self::RENT_ID, '2027-01', '-10'), $invalid);
            self::fail('invalid cell must reject the batch: '.$case);
        } catch (InvalidBudgetInputException) {
        }
        self::assertSame(0, $this->countRows('budget_lines'));
    }

    /**
     * @return iterable<string, array{BudgetVersionStatus}>
     */
    public static function lockedStatuses(): iterable
    {
        yield 'approved' => [BudgetVersionStatus::APPROVED];
        yield 'archived' => [BudgetVersionStatus::ARCHIVED];
    }

    #[DataProvider('lockedStatuses')]
    public function testOnlyDraftIsEditable(BudgetVersionStatus $status): void
    {
        $this->givenVersion($status);

        $this->expectException(BudgetVersionNotEditableException::class);
        $this->save($this->cell(self::REVENUE_ID, '2027-01', '1'));
    }

    public function testForeignVersionIsNotFound(): void
    {
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::OTHER_COMPANY_ID)->build());
        $this->em->flush();

        $this->expectException(BudgetVersionNotFoundException::class);
        $this->save($this->cell(self::REVENUE_ID, '2027-01', '1'));
    }

    public function testTooLargeBatchIsRejected(): void
    {
        $this->givenVersion();

        $this->expectException(InvalidBudgetInputException::class);
        $this->save(...array_fill(0, SaveBudgetLinesAction::MAX_CELLS + 1, $this->cell(self::REVENUE_ID, '2027-01', '1')));
    }

    public function testUppercaseVersionIdWaitsForSameLock(): void
    {
        $this->testWaitsForConcurrentStatusChangeOfSameVersion(strtoupper(self::VERSION_ID));
    }

    public function testWaitsForConcurrentStatusChangeOfSameVersion(string $requestedVersionId = self::VERSION_ID): void
    {
        $this->givenVersion();

        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'budget_version:'.self::VERSION_ID]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                $this->saveTo($requestedVersionId, $this->cell(self::REVENUE_ID, '2027-01', '1'));
                self::fail('save must wait for the version lock');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    private function givenVersion(BudgetVersionStatus $status = BudgetVersionStatus::DRAFT): void
    {
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->withStatus($status)->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function cell(string $categoryId, string $month, string $amount, ?string $projectId = null, ?string $centerId = null, ?string $comment = null): BudgetCellInput
    {
        return new BudgetCellInput($categoryId, $month, $projectId ?? self::GENERAL_PROJECT_ID, $centerId ?? $this->generalCenterId, $amount, $comment);
    }

    private function save(BudgetCellInput ...$cells): int
    {
        return $this->saveTo(self::VERSION_ID, ...$cells);
    }

    private function saveTo(string $versionId, BudgetCellInput ...$cells): int
    {
        return self::getContainer()->get(SaveBudgetLinesAction::class)(new SaveBudgetLinesCommand(self::COMPANY_ID, $versionId, array_values($cells)));
    }
}
