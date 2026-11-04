<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\Service\BudgetYearTotals;
use App\Tests\Builders\Budget\BudgetLineBuilder;
use App\Tests\Builders\Budget\BudgetVersionBuilder;

final class BudgetYearTotalsTest extends BudgetIntegrationTestCase
{
    private const BASE_ID = '0193cccc-0000-7000-8000-0000000f9001';
    private const OPTIMISTIC_ID = '0193cccc-0000-7000-8000-0000000f9002';
    private const EMPTY_ID = '0193cccc-0000-7000-8000-0000000f9003';

    protected function setUp(): void
    {
        parent::setUp();
        $base = BudgetVersionBuilder::aVersion()->withId(self::BASE_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->build();
        $optimistic = BudgetVersionBuilder::aVersion()->withId(self::OPTIMISTIC_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->withName('Оптимистичный')->build();
        $empty = BudgetVersionBuilder::aVersion()->withId(self::EMPTY_ID)->forCompany(self::COMPANY_ID)->withYear(2027)->withName('Пустой')->build();
        $n = 0;
        foreach ([[$base, self::REVENUE_ID, '2027-01', '1000.00'], [$base, self::RENT_ID, '2027-02', '-300.00'], [$base, self::REVENUE_ID, '2027-03', '500.00', true], [$optimistic, self::REVENUE_ID, '2027-01', '2000.00']] as $line) {
            $builder = BudgetLineBuilder::aLine()->withId(sprintf('0193cccc-0000-7000-8000-0000000fa%03d', ++$n))->inVersion($line[0])->forCategory($line[1])->forMonth($line[2])->withAmount($line[3]);
            $builder = isset($line[4]) ? $builder->forPair(self::SHOP_PROJECT_ID, $this->salesCenterId) : $builder->forPair(self::GENERAL_PROJECT_ID, $this->generalCenterId);
            $this->em->persist($builder->build());
        }
        foreach ([$base, $optimistic, $empty] as $version) {
            $this->em->persist($version);
        }
        $this->em->flush();
        $this->em->clear();
    }

    public function testWithoutNetProfitRowThereIsNoColumn(): void
    {
        self::assertNull($this->totals()->netProfit(self::COMPANY_ID, 2027, [self::BASE_ID]));
    }

    public function testNetProfitOfEachVersionOverAllPairs(): void
    {
        $this->connection->executeStatement("UPDATE pl_categories SET code = 'NET_PROFIT' WHERE id = ?", [self::SUBTOTAL_ID]);

        self::assertSame(
            [self::BASE_ID => '1200.00', self::OPTIMISTIC_ID => '2000.00', self::EMPTY_ID => '0.00'],
            $this->totals()->netProfit(self::COMPANY_ID, 2027, [self::BASE_ID, self::OPTIMISTIC_ID, self::EMPTY_ID]),
        );
        self::assertSame([], $this->totals()->netProfit(self::COMPANY_ID, 2027, []));
    }

    private function totals(): BudgetYearTotals
    {
        return self::getContainer()->get(BudgetYearTotals::class);
    }
}
