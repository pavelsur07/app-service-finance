<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Marketplace\Entity\MarketplaceBarcodeCatalog;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceBarcodeCatalogRepository;
use Ramsey\Uuid\Uuid;

/**
 * Справочник barcode→size для всех маркетплейсов.
 * Заполняется при обработке rawData (продажи/возвраты содержат barcode+size).
 * Используется при обработке затрат где size отсутствует.
 */
final class MarketplaceBarcodeCatalogService
{
    public function __construct(
        private readonly MarketplaceBarcodeCatalogRepository $repository,
    ) {
    }

    /**
     * Заполнить каталог из массива rawRows WB.
     * Вызывается при обработке продаж и возвратов.
     *
     * @param array<int, array<string, mixed>> $rawRows
     */
    public function fillFromWbRows(string $companyId, array $rawRows): void
    {
        $rows = [];

        foreach ($rawRows as $item) {
            $nmId = trim((string) ($item['nm_id'] ?? ''));
            $barcode = trim((string) ($item['barcode'] ?? ''));
            $tsName = trim((string) ($item['ts_name'] ?? ''));

            if ($nmId === '' || $nmId === '0' || $barcode === '' || $tsName === '') {
                continue;
            }

            $rows[] = [
                'id'          => Uuid::uuid4()->toString(),
                'companyId'   => $companyId,
                'marketplace' => MarketplaceType::WILDBERRIES,
                'externalId'  => $nmId,
                'barcode'     => $barcode,
                'size'        => $tsName,
            ];
        }

        if (!empty($rows)) {
            $this->repository->upsertBatch($rows);
        }
    }

    /**
     * Получить size по barcode из каталога.
     */
    public function findSizeByBarcode(
        string $companyId,
        MarketplaceType $marketplace,
        string $barcode,
    ): ?string {
        $entry = $this->repository->findByBarcode($companyId, $marketplace, $barcode);

        return $entry?->getSize();
    }

    /**
     * Массовый поиск — индексировано по barcode.
     *
     * @param string[] $barcodes
     * @return array<string, string> [barcode => size]
     */
    public function findSizesByBarcodes(
        string $companyId,
        MarketplaceType $marketplace,
        array $barcodes,
    ): array {
        $entries = $this->repository->findByBarcodesIndexed($companyId, $marketplace, $barcodes);

        $result = [];
        foreach ($entries as $barcode => $entry) {
            $result[$barcode] = $entry->getSize();
        }

        return $result;
    }
}
