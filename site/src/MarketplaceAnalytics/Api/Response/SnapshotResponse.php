<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Response;

use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SnapshotResponse',
    description: 'Снэпшот маркетплейс-аналитики за один день по одному листингу',
    required: [
        'id',
        'listing_id',
        'listing_name',
        'listing_sku',
        'marketplace',
        'snapshot_date',
        'revenue',
        'refunds',
        'sales_quantity',
        'returns_quantity',
        'orders_quantity',
        'delivered_quantity',
        'avg_sale_price',
        'cost_price',
        'total_cost_price',
        'cost_breakdown',
        'advertising_details',
        'data_quality',
        'calculated_at',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'listing_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'listing_name', type: 'string', example: 'Кружка керамическая 350мл'),
        new OA\Property(property: 'listing_sku', type: 'string', example: 'SKU-12345'),
        new OA\Property(property: 'marketplace', type: 'string', example: 'OZON'),
        new OA\Property(property: 'snapshot_date', type: 'string', format: 'date', example: '2026-04-21'),
        new OA\Property(property: 'revenue', type: 'string', description: 'Десятичное значение выручки', example: '12345.67'),
        new OA\Property(property: 'refunds', type: 'string', description: 'Десятичное значение возвратов', example: '123.45'),
        new OA\Property(property: 'sales_quantity', type: 'integer', example: 42),
        new OA\Property(property: 'returns_quantity', type: 'integer', example: 3),
        new OA\Property(property: 'orders_quantity', type: 'integer', example: 45),
        new OA\Property(property: 'delivered_quantity', type: 'integer', example: 40),
        new OA\Property(property: 'avg_sale_price', type: 'string', description: 'Средняя цена продажи (десятичное)', example: '299.99'),
        new OA\Property(property: 'cost_price', type: 'string', nullable: true, description: 'Себестоимость единицы (десятичное)', example: '150.00'),
        new OA\Property(property: 'total_cost_price', type: 'string', nullable: true, description: 'Суммарная себестоимость за день (десятичное)', example: '6000.00'),
        new OA\Property(property: 'cost_breakdown', type: 'object', description: 'Разбивка затрат по категориям', additionalProperties: true),
        new OA\Property(property: 'advertising_details', type: 'object', description: 'Детализация рекламных расходов', additionalProperties: true),
        new OA\Property(property: 'data_quality', type: 'array', description: 'Список флагов качества данных снэпшота (значения enum DataQualityFlag)', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', example: '2026-04-21T03:15:00+00:00'),
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
