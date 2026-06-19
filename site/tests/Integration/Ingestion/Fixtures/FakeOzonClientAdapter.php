<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Fixtures;

use App\Ingestion\Infrastructure\Api\Ozon\OzonClientAdapterInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use App\Ingestion\Infrastructure\Api\Ozon\OzonShopDescriptor;

final class FakeOzonClientAdapter implements OzonClientAdapterInterface
{
    public function fetchTransactionList(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $page,
        int $pageSize,
    ): OzonRawPage {
        if (1 !== $page) {
            return new OzonRawPage(rows: [], hasMore: false, metadata: ['page' => $page]);
        }

        return new OzonRawPage(
            rows: [[
                'operation_id' => 'fake-ozon-op-1',
                'operation_type' => 'OperationAgentDeliveredToCustomer',
                'operation_type_name' => 'Delivery to customer',
                'operation_date' => '2026-06-18T09:30:00+00:00',
                'posting' => ['posting_number' => 'posting-1'],
                'items' => [['sku' => 'sku-1', 'name' => 'Product 1']],
                'accruals_for_sale' => '120.50',
                'sale_commission_amount' => '-12.05',
                'deliv_charge_amount' => '-3.50',
                'services_amounts' => [
                    'MarketplaceServiceItemDelivToCustomer' => '-2.50',
                ],
                'currency' => 'RUB',
            ]],
            hasMore: false,
            metadata: ['page' => 1],
        );
    }

    public function fetchRealization(
        string $companyId,
        string $connectionRef,
        int $year,
        int $month,
    ): OzonRawPage {
        return new OzonRawPage(
            rows: [[
                'operation_id' => 'fake-ozon-op-1',
                'operation_type' => 'OperationAgentDeliveredToCustomer',
                'operation_type_name' => 'Final delivery to customer',
                'sale_date' => '2026-06-18T09:30:00+00:00',
                'report_date' => '2026-07-05T00:00:00+00:00',
                'posting_number' => 'posting-1',
                'sku' => 'sku-1',
                'seller_price' => '121.00',
                'commission_amount' => '-12.10',
                'delivery_commission' => '-3.50',
                'currency' => 'RUB',
            ]],
            hasMore: false,
            metadata: [
                'year' => $year,
                'month' => $month,
                'header' => [
                    'doc_number' => 'realization-'.$year.'-'.$month,
                    'stop_date' => sprintf('%04d-%02d-28T00:00:00+00:00', $year, $month),
                ],
            ],
        );
    }

    /**
     * @return list<OzonShopDescriptor>
     */
    public function listClusters(string $companyId, string $connectionRef): array
    {
        return [
            new OzonShopDescriptor(
                externalId: $connectionRef,
                name: 'Fake Ozon Seller',
                currency: 'RUB',
                metadata: ['companyId' => $companyId],
            ),
        ];
    }
}
