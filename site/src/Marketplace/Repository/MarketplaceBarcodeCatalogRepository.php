<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceBarcodeCatalog;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceBarcodeCatalogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceBarcodeCatalog::class);
    }

    /**
     * Найти запись по barcode.
     */
    public function findByBarcode(
        string $companyId,
        MarketplaceType $marketplace,
        string $barcode,
    ): ?MarketplaceBarcodeCatalog {
        return $this->findOneBy([
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'barcode'     => $barcode,
        ]);
    }

    /**
     * Массовая загрузка по списку barcodes — индексировано по barcode.
     *
     * @param string[] $barcodes
     * @return array<string, MarketplaceBarcodeCatalog>
     */
    public function findByBarcodesIndexed(
        string $companyId,
        MarketplaceType $marketplace,
        array $barcodes,
    ): array {
        if (empty($barcodes)) {
            return [];
        }

        $results = $this->createQueryBuilder('c')
            ->where('c.companyId = :companyId')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.barcode IN (:barcodes)')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('barcodes', $barcodes)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($results as $entry) {
            $indexed[$entry->getBarcode()] = $entry;
        }

        return $indexed;
    }

    /**
     * Upsert записей каталога — вставить новые, обновить существующие.
     * Дедуплицирует внутри батча перед вставкой.
     *
     * @param array<int, array{id: string, companyId: string, marketplace: MarketplaceType, externalId: string, barcode: string, size: string}> $rows
     */
    public function upsertBatch(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        // Дедупликация внутри батча по ключу companyId+marketplace+barcode
        $deduped = [];
        foreach ($rows as $row) {
            $key = $row['companyId'] . '_' . $row['marketplace']->value . '_' . $row['barcode'];
            $deduped[$key] = $row;
        }

        $conn = $this->getEntityManager()->getConnection();

        foreach (array_chunk(array_values($deduped), 500) as $chunk) {
            $placeholders = [];
            $params = [];
            $i = 0;

            foreach ($chunk as $row) {
                $placeholders[] = "(:id$i, :companyId$i, :marketplace$i, :externalId$i, :barcode$i, :size$i)";
                $params["id$i"]          = $row['id'];
                $params["companyId$i"]   = $row['companyId'];
                $params["marketplace$i"] = $row['marketplace']->value;
                $params["externalId$i"]  = $row['externalId'];
                $params["barcode$i"]     = $row['barcode'];
                $params["size$i"]        = $row['size'];
                $i++;
            }

            $sql = sprintf(
                'INSERT INTO marketplace_barcode_catalog (id, company_id, marketplace, external_id, barcode, size)
                 VALUES %s
                 ON CONFLICT (company_id, marketplace, barcode) DO UPDATE SET
                     size        = EXCLUDED.size,
                     external_id = EXCLUDED.external_id',
                implode(', ', $placeholders),
            );

            $conn->executeStatement($sql, $params);
        }
    }
}
