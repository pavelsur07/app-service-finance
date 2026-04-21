<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SnapshotResponse',
    description: 'Снэпшот маркетплейс-аналитики по конкретному листингу за день',
    required: [
        'id',
        'listing_id',
        'listing_name',
        'listing_sku',
        'marketplace',
        'snapshot_date',
        'calculated_at',
        'revenue',
        'refunds',
        'avg_sale_price',
        'orders_quantity',
        'sales_quantity',
        'delivered_quantity',
        'returns_quantity',
        'data_quality',
        'cost_breakdown',
        'advertising_details',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'listing_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'listing_name', type: 'string', example: 'Мышеловка. Крысоловка металлическая'),
        new OA\Property(property: 'listing_sku', type: 'string', example: '3230225798'),
        new OA\Property(
            property: 'marketplace',
            type: 'string',
            enum: ['wildberries', 'ozon', 'yandex_market', 'sber_megamarket'],
            example: 'ozon',
            description: 'Код маркетплейса (значения enum MarketplaceType)',
        ),
        new OA\Property(property: 'snapshot_date', type: 'string', format: 'date', example: '2026-04-09'),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', example: '2026-04-10T08:11:55+03:00'),
        new OA\Property(property: 'revenue', type: 'string', example: '0.00', description: 'Выручка (decimal as string)'),
        new OA\Property(property: 'refunds', type: 'string', example: '0.00', description: 'Возвраты (decimal as string)'),
        new OA\Property(property: 'avg_sale_price', type: 'string', example: '0.00', description: 'Средняя цена продажи (decimal as string)'),
        new OA\Property(property: 'cost_price', type: 'string', nullable: true, example: null, description: 'Себестоимость единицы; null если не указана'),
        new OA\Property(property: 'total_cost_price', type: 'string', nullable: true, example: null, description: 'Общая себестоимость за период; null если не рассчитана'),
        new OA\Property(property: 'orders_quantity', type: 'integer', example: 0),
        new OA\Property(property: 'sales_quantity', type: 'integer', example: 0),
        new OA\Property(property: 'delivered_quantity', type: 'integer', example: 0),
        new OA\Property(property: 'returns_quantity', type: 'integer', example: 0),
        new OA\Property(
            property: 'data_quality',
            type: 'array',
            items: new OA\Items(
                type: 'string',
                enum: [
                    'cost_price_missing',
                    'api_advertising_missing',
                    'api_storage_missing',
                    'api_orders_missing',
                    'data_delayed',
                ],
            ),
            example: ['cost_price_missing', 'api_advertising_missing'],
            description: 'Флаги проблем с качеством данных (значения enum DataQualityFlag)',
        ),
        new OA\Property(property: 'cost_breakdown', ref: '#/components/schemas/CostBreakdown'),
        new OA\Property(property: 'advertising_details', ref: '#/components/schemas/AdvertisingDetails'),
    ]
)]
final readonly class SnapshotResponse
{
    public function __construct(
        private string $id,
        private string $listingId,
        private string $listingName,
        private string $listingSku,
        private string $marketplace,
        private string $snapshotDate,
        private string $revenue,
        private string $refunds,
        private int $salesQuantity,
        private int $returnsQuantity,
        private int $ordersQuantity,
        private int $deliveredQuantity,
        private string $avgSalePrice,
        private ?string $costPrice,
        private ?string $totalCostPrice,
        private array $costBreakdown,
        private array $advertisingDetails,
        private array $dataQuality,
        private string $calculatedAt,
    ) {}

    public static function fromEntity(
        ListingDailySnapshot $snapshot,
        string $name,
        string $sku,
    ): self {
        return new self(
            id: $snapshot->getId(),
            listingId: $snapshot->getListingId(),
            listingName: $name,
            listingSku: $sku,
            marketplace: $snapshot->getMarketplace()->value,
            snapshotDate: $snapshot->getSnapshotDate()->format('Y-m-d'),
            revenue: $snapshot->getRevenue(),
            refunds: $snapshot->getRefunds(),
            salesQuantity: $snapshot->getSalesQuantity(),
            returnsQuantity: $snapshot->getReturnsQuantity(),
            ordersQuantity: $snapshot->getOrdersQuantity(),
            deliveredQuantity: $snapshot->getDeliveredQuantity(),
            avgSalePrice: $snapshot->getAvgSalePrice(),
            costPrice: $snapshot->getCostPrice(),
            totalCostPrice: $snapshot->getTotalCostPrice(),
            costBreakdown: $snapshot->getCostBreakdown(),
            advertisingDetails: $snapshot->getAdvertisingDetails(),
            dataQuality: $snapshot->getDataQuality(),
            calculatedAt: $snapshot->getCalculatedAt()->format(\DATE_ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listingId,
            'listing_name' => $this->listingName,
            'listing_sku' => $this->listingSku,
            'marketplace' => $this->marketplace,
            'snapshot_date' => $this->snapshotDate,
            'revenue' => $this->revenue,
            'refunds' => $this->refunds,
            'sales_quantity' => $this->salesQuantity,
            'returns_quantity' => $this->returnsQuantity,
            'orders_quantity' => $this->ordersQuantity,
            'delivered_quantity' => $this->deliveredQuantity,
            'avg_sale_price' => $this->avgSalePrice,
            'cost_price' => $this->costPrice,
            'total_cost_price' => $this->totalCostPrice,
            'cost_breakdown' => $this->costBreakdown,
            'advertising_details' => $this->advertisingDetails,
            'data_quality' => $this->dataQuality,
            'calculated_at' => $this->calculatedAt,
        ];
    }
}
