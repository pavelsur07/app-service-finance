<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Domain\Policy;

use App\Balance\Domain\Policy\BalanceEquationPolicy;
use App\Balance\DTO\BalanceRowView;
use App\Balance\ReadModel\BalanceReport;
use PHPUnit\Framework\TestCase;

final class BalanceEquationPolicyTest extends TestCase
{
    public function testReturnsEmptyWhenEquationHolds(): void
    {
        $policy = new BalanceEquationPolicy();
        $report = new BalanceReport(
            date: new \DateTimeImmutable(),
            currencies: ['RUB'],
            roots: [
                new BalanceRowView(
                    id: '1',
                    name: 'Активы',
                    type: 'asset',
                    level: 1,
                    sortOrder: 1,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '150.00'],
                ),
                new BalanceRowView(
                    id: '2',
                    name: 'Обязательства',
                    type: 'liability',
                    level: 1,
                    sortOrder: 2,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '100.00'],
                ),
                new BalanceRowView(
                    id: '3',
                    name: 'Капитал',
                    type: 'equity',
                    level: 1,
                    sortOrder: 3,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '50.00'],
                ),
            ],
            totals: ['RUB' => '0'],
        );

        self::assertSame([], $policy->check($report));
    }

    public function testReturnsErrorWhenEquationDoesNotHold(): void
    {
        $policy = new BalanceEquationPolicy();
        $report = new BalanceReport(
            date: new \DateTimeImmutable(),
            currencies: ['RUB'],
            roots: [
                new BalanceRowView(
                    id: '1',
                    name: 'Активы',
                    type: 'asset',
                    level: 1,
                    sortOrder: 1,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '150.00'],
                ),
                new BalanceRowView(
                    id: '2',
                    name: 'Обязательства',
                    type: 'liability',
                    level: 1,
                    sortOrder: 2,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '80.00'],
                ),
                new BalanceRowView(
                    id: '3',
                    name: 'Капитал',
                    type: 'equity',
                    level: 1,
                    sortOrder: 3,
                    isVisible: true,
                    amountsByCurrency: ['RUB' => '50.00'],
                ),
            ],
            totals: ['RUB' => '0'],
        );

        $errors = $policy->check($report);

        self::assertCount(1, $errors);
        self::assertStringContainsString('RUB', $errors[0]);
    }
}
