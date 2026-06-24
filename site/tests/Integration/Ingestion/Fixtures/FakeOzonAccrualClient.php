<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Infrastructure\Api\Ozon\OzonAccrualClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;

final class FakeOzonAccrualClient implements OzonAccrualClientInterface
{
    public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
    {
        return new OzonRawPage(rows: [], hasMore: false);
    }

    public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
    {
        return new OzonRawPage(
            rows: [[
                'accrual_id' => 53675409100,
                'date' => $date->format('Y-m-d'),
                'unit_number' => '41774559-0885-1',
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'delivery' => [
                            'services' => [
                                ['type_id' => 29, 'accrued' => ['amount' => '-7.86', 'currency' => 'RUB']],
                            ],
                        ],
                        'commission' => [
                            'commission' => ['amount' => '-120.05', 'currency' => 'RUB'],
                            'sale_amount' => ['amount' => '66718', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ]],
            hasMore: false,
            metadata: ['endpoint' => '/v1/finance/accrual/by-day'],
        );
    }

    public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
    {
        return new OzonRawPage(rows: [['type_id' => 29, 'name' => 'Логистика']], hasMore: false);
    }
}
