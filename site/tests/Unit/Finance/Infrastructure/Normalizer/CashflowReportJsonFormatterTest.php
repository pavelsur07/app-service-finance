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
            'projectCenterMatrix' => [
                'currencies' => ['USD'],
                'rowsByCenter' => [[
                    'project_id' => '33333333-3333-4333-8333-333333333333',
                    'project_name' => 'Main',
                    'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
                    'responsibility_center_name' => null,
                    'totals' => ['USD' => [123.45]],
                ]],
                'rowsByProject' => [[
                    'project_id' => '33333333-3333-4333-8333-333333333333',
                    'project_name' => 'Main',
                    'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
                    'responsibility_center_name' => null,
                    'totals' => ['USD' => [123.45]],
                ]],
            ],
        ]);

        self::assertSame([
            'company' => $company->getId(),
            'group' => 'month',
            'responsibility_center_id' => null,
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
            'projectCenterMatrix' => [
                'currencies' => ['USD'],
                'rowsByCenter' => [[
                    'project_id' => '33333333-3333-4333-8333-333333333333',
                    'project_name' => 'Main',
                    'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
                    'responsibility_center_name' => null,
                    'totals' => ['USD' => [123.45]],
                ]],
                'rowsByProject' => [[
                    'project_id' => '33333333-3333-4333-8333-333333333333',
                    'project_name' => 'Main',
                    'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
                    'responsibility_center_name' => null,
                    'totals' => ['USD' => [123.45]],
                ]],
            ],
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
            'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
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
            'responsibility_center_id' => '44444444-4444-4444-8444-444444444444',
            'date_from' => '2026-02-01',
            'date_to' => '2026-02-02',
        ], $formatted['filters']);
    }

    public function testFormatAddsPluralFiltersOnlyWhenTheyAreApplied(): void
    {
        $formatter = new CashflowReportJsonFormatter();
        $company = new Company('55555555-5555-4555-8555-555555555555', $this->createUser());
        $projectIds = ['66666666-6666-4666-8666-666666666666'];
        $centerIds = [
            '77777777-7777-4777-8777-777777777777',
            '88888888-8888-4888-8888-888888888888',
        ];

        $formatted = $formatter->format([
            'company' => $company,
            'group' => 'quarter',
            'responsibility_center_id' => null,
            'project_direction_ids' => $projectIds,
            'responsibility_center_ids' => $centerIds,
            'date_from' => new \DateTimeImmutable('2026-01-01'),
            'date_to' => new \DateTimeImmutable('2026-03-31'),
            'periods' => [],
            'categories' => [],
            'categoryTotals' => [],
            'openings' => [],
            'closings' => [],
            'tree' => [],
            'categoryTree' => [],
        ], ['include_filters' => true]);

        self::assertSame($projectIds, $formatted['filters']['project_direction_ids']);
        self::assertSame($centerIds, $formatted['filters']['responsibility_center_ids']);
        self::assertArrayNotHasKey('project_direction_ids', $formatted);
        self::assertArrayNotHasKey('responsibility_center_ids', $formatted);
    }

    public function testFormatAddsDashboardReconciliationOnlyForOptInPayload(): void
    {
        $formatter = new CashflowReportJsonFormatter();
        $company = new Company('99999999-9999-4999-8999-999999999999', $this->createUser());
        $reconciliation = [
            'mode' => 'dashboard',
            'activity' => 'operating',
            'currency' => 'RUB',
            'inflow' => '100.00',
            'outflow' => '60.00',
            'net' => '40.00',
        ];

        $formatted = $formatter->format([
            'company' => $company,
            'group' => 'month',
            'date_from' => new \DateTimeImmutable('2026-07-24'),
            'date_to' => new \DateTimeImmutable('2026-08-22'),
            'periods' => [],
            'categories' => [],
            'categoryTotals' => [],
            'openings' => [],
            'closings' => [],
            'tree' => [],
            'categoryTree' => [],
            'dashboardReconciliation' => $reconciliation,
        ], ['include_filters' => true]);

        self::assertSame($reconciliation, $formatted['dashboard_reconciliation']);
        self::assertSame('dashboard', $formatted['filters']['reconcile']);
        self::assertSame('operating', $formatted['filters']['activity']);
        self::assertSame('RUB', $formatted['filters']['currency']);
    }

    private function createUser(): User
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('cashflow-json-formatter@example.com');
        $user->setPassword('pass');

        return $user;
    }
}
