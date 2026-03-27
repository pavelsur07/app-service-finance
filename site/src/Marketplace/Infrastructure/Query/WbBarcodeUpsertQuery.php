<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Идемпотентная вставка баркода листинга WB.
 *
 * ON CONFLICT (company_id, marketplace, barcode) DO NOTHING гарантирует:
 * - отсутствие ошибки при повторной обработке одного и того же raw-документа;
 * - отсутствие конфликта между маркетплейсами (у Ozon и WB может быть один баркод).
 *
 * Вызывается ПОСЛЕ em->flush() листинга, чтобы FK listing_id был уже в БД.
 */
final class WbBarcodeUpsertQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsertIfNotExists(string $companyId, string $listingId, string $barcode): void
    {
        $this->connection->executeStatement(
            'INSERT INTO marketplace_listing_barcodes (id, listing_id, company_id, marketplace, barcode)
             VALUES (:id, :listingId, :companyId, :marketplace, :barcode)
             ON CONFLICT (company_id, marketplace, barcode) DO NOTHING',
            [
                'id'          => Uuid::uuid7()->toString(),
                'listingId'   => $listingId,
                'companyId'   => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES->value,
                'barcode'     => $barcode,
            ],
        );
    }
}
