<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Report;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Facts\FactsProviderInterface;
use App\Finance\Report\PlReportCalculator;
use App\Finance\Report\PlReportPeriod;
use App\Finance\Repository\PLCategoryRepository;
use PHPUnit\Framework\TestCase;

final class PlReportCalculatorTreeDepthTest extends TestCase
{
    /**
     * Прод-сценарий ИП Лазарева: группу вынесли в корень, а сохранённый level
     * её статей остался 3. Отчёт обязан брать глубину из дерева, иначе он
     * рисует листовую статью «Налоги на ФОТ» группой.
     */
    public function testRowLevelFollowsTreeDepthNotStoredLevel(): void
    {
        $company = $this->createMock(Company::class);
        $overhead = (new PLCategory('00000000-0000-0000-0000-000000000001', $company))->setName('Общепроизводственные')->setSortOrder(49);
        $payroll = (new PLCategory('00000000-0000-0000-0000-000000000002', $company))->setName('ЗП')->setSortOrder(5);
        $tax = (new PLCategory('00000000-0000-0000-0000-000000000003', $company))->setName('Налоги на ФОТ')->setSortOrder(6);
        $cert = (new PLCategory('00000000-0000-0000-0000-000000000004', $company))->setName('Сертификация')->setSortOrder(8);
        foreach ([$payroll, $tax, $cert] as $child) {
            $child->setParent($overhead);
        }
        $payroll->setLevel(3);
        $cert->setLevel(3);

        $repo = $this->createMock(PLCategoryRepository::class);
        $repo->method('findBy')->willReturn([$overhead, $payroll, $tax, $cert]);
        $facts = $this->createMock(FactsProviderInterface::class);

        $res = (new PlReportCalculator($repo, $facts))->calculate($company, PlReportPeriod::forMonth(new \DateTimeImmutable('2026-09-01')));

        self::assertSame(
            ['Общепроизводственные' => 1, 'ЗП' => 2, 'Налоги на ФОТ' => 2, 'Сертификация' => 2],
            array_column(array_map(static fn ($r): array => ['name' => $r->name, 'level' => $r->level], $res->rows), 'level', 'name'),
        );
    }
}
