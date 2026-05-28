<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Infrastructure\Normalizer;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Finance\Infrastructure\Normalizer\CashflowReportJsonFormatter;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashflowReportJsonFormatterTest extends TestCase
{
    public function testFormatKeepsPublicJsonContractSerializable(): void
    {
        $formatter = new CashflowReportJsonFormatter();
        $user = $this->createUser();
        $company = new Company('11111111-1111-4111-8111-111111111111', $user);
        $company->setName('Cashflow Co');
        $category = new CashflowCategory('22222222-2222-4222-8222-222222222222', $company);
        $category->setName('Operations');

        $tree = [[
            'id' => $category->getId(),
            'name' => $category->getName(),
            'level' => 0,
            'totals' => ['USD' => [123.45]],
            'children' => [],
        ]];
        $categoryTree = [[
            'id' => $category->getId(),
            'name' => $category->getName(),
            'parentId' => null,
            'level' => 0,
            'order' => 0,
        ]];

        $formatted = $formatter->format([
            'company' => $company,
            'group' => 'month',
            'date_from' => new \DateTimeImmutable('2026-01-01'),
            'date_to' => new \DateTimeImmutable('2026-01-31'),
            'periods' => [[
                'start' => new \DateTimeImmutable('2026-01-01'),
                'end' => new \DateTimeImmutable('2026-01-31'),
                'label' => 'Jan 2026',
            ]],
            'categories' => [$category],
            'categoryTotals' => [
                $category->getId() => [
                    'entity' => $category,
                    'totals' => ['USD' => [123.45]],
                ],
            ],
            'openings' => ['USD' => [10.0]],
            'closings' => ['USD' => [133.45]],
            'tree' => $tree,
            'categoryTree' => $categoryTree,
        ]);

        self::assertSame([
            'company' => $company->getId(),
            'group' => 'month',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'periods' => [[
                'start' => '2026-01-01',
                'end' => '2026-01-31',
                'label' => 'Jan 2026',
            ]],
            'categories' => [[
                'id' => $category->getId(),
                'name' => 'Operations',
            ]],
            'categoryTotals' => [
                $category->getId() => [
                    'totals' => ['USD' => [123.45]],
                ],
            ],
            'openings' => ['USD' => [10.0]],
            'closings' => ['USD' => [133.45]],
            'tree' => $tree,
            'categoryTree' => $categoryTree,
        ], $formatted);
    }

    public function testFormatCanAddExportMetadataAndFilters(): void
    {
        $formatter = new CashflowReportJsonFormatter();
        $user = $this->createUser();
        $company = new Company('33333333-3333-4333-8333-333333333333', $user);

        $formatted = $formatter->format([
            'company' => $company,
            'group' => 'day',
            'date_from' => new \DateTimeImmutable('2026-02-01'),
            'date_to' => new \DateTimeImmutable('2026-02-02'),
            'periods' => [],
            'categories' => [],
            'categoryTotals' => [],
            'openings' => [],
            'closings' => [],
            'tree' => [],
            'categoryTree' => [],
        ], [
            'include_exported_at' => true,
            'exported_at' => new \DateTimeImmutable('2026-02-03T04:05:06+00:00'),
            'dataset' => 'cashflow',
            'include_filters' => true,
        ]);

        self::assertSame('2026-02-03T04:05:06+00:00', $formatted['exported_at']);
        self::assertSame('cashflow', $formatted['dataset']);
        self::assertSame([
            'group' => 'day',
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-02',
        ], $formatted['filters']);
    }

    private function createUser(): User
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-json-formatter@example.com');
        $user->setPassword('pass');

        return $user;
    }
}
