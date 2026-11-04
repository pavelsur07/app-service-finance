<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\CreateBudgetVersionAction;
use App\Budget\Application\DTO\CreateBudgetVersionCommand;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;

final class CreateBudgetVersionActionTest extends BudgetIntegrationTestCase
{
    private const NEW_ID = '0193cccc-0000-7000-8000-0000000f0001';
    private const SOURCE_ID = '0193cccc-0000-7000-8000-0000000f0002';

    public function testCreatesEmptyDraft(): void
    {
        $id = $this->create(new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, '  Базовый ', year: 2027));

        self::assertSame(self::NEW_ID, $id);
        self::assertSame(
            ['company_id' => self::COMPANY_ID, 'year' => 2027, 'name' => 'Базовый', 'status' => 'draft', 'copied_from_id' => null],
            $this->connection->fetchAssociative('SELECT company_id, year, name, status, copied_from_id FROM budget_versions WHERE id = ?', [self::NEW_ID]),
        );
        self::assertSame(0, $this->countRows('budget_lines'));
    }

    public function testRepeatedCallReturnsSameVersionAndRowCount(): void
    {
        $command = new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, 'Базовый', year: 2027);

        self::assertSame(self::NEW_ID, $this->create($command));
        $this->em->clear();
        self::assertSame(self::NEW_ID, $this->create($command));

        self::assertSame(1, $this->countRows('budget_versions'));
    }

    public function testCopyTakesYearFromSourceAndCopiesAllLines(): void
    {
        $source = BudgetVersionBuilder::aVersion()->withId(self::SOURCE_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->withStatus(BudgetVersionStatus::APPROVED)->build();
        $this->em->persist($source);
        $this->em->persist(BudgetLineBuilder::aLine()->withId('0193cccc-0000-7000-8000-0000000f1001')->inVersion($source)->forCategory(self::REVENUE_ID)->forMonth('2027-01')->forPair(self::GENERAL_PROJECT_ID, $this->generalCenterId)->withAmount('500000.00')->build());
        $this->em->persist(BudgetLineBuilder::aLine()->withId('0193cccc-0000-7000-8000-0000000f1002')->inVersion($source)->forCategory(self::RENT_ID)->forMonth('2027-02')->forPair(self::SHOP_PROJECT_ID, $this->salesCenterId)->withAmount('-60000.00')->withComment('склад')->build());
        $this->em->flush();
        $this->em->clear();

        $this->create(new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, 'Оптимистичный', year: 2030, copyFromVersionId: self::SOURCE_ID));

        self::assertSame(
            ['year' => 2027, 'status' => 'draft', 'copied_from_id' => self::SOURCE_ID],
            $this->connection->fetchAssociative('SELECT year, status, copied_from_id FROM budget_versions WHERE id = ?', [self::NEW_ID]),
        );
        self::assertSame($this->lines(self::SOURCE_ID), $this->lines(self::NEW_ID));
        self::assertCount(2, $this->lines(self::NEW_ID));
        self::assertSame('approved', $this->connection->fetchOne('SELECT status FROM budget_versions WHERE id = ?', [self::SOURCE_ID]));
    }

    public function testCopyOfForeignVersionIsNotFound(): void
    {
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::SOURCE_ID)->forCompany(self::OTHER_COMPANY_ID)->build());
        $this->em->flush();

        try {
            $this->create(new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, 'Копия', copyFromVersionId: self::SOURCE_ID));
            self::fail('foreign source must not be found');
        } catch (BudgetVersionNotFoundException) {
        }
        self::assertSame(0, $this->countRows('budget_versions', 'company_id = ?', [self::COMPANY_ID]));
    }

    public function testEmptyVersionRequiresValidYearAndName(): void
    {
        foreach ([
            new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, 'Без года'),
            new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, 'Старый', year: 1999),
            new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, '   ', year: 2027),
            new CreateBudgetVersionCommand(self::NEW_ID, self::COMPANY_ID, str_repeat('я', 121), year: 2027),
        ] as $command) {
            try {
                $this->create($command);
                self::fail('invalid input must be rejected: '.$command->name);
            } catch (InvalidBudgetInputException) {
            }
        }
        self::assertSame(0, $this->countRows('budget_versions'));
    }

    private function create(CreateBudgetVersionCommand $command): string
    {
        return self::getContainer()->get(CreateBudgetVersionAction::class)($command);
    }
}
