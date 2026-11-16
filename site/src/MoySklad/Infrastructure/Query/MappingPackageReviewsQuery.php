<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webmozart\Assert\Assert;

final readonly class MappingPackageReviewsQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @param list<string> $listingIds
     * @return array<string,list<array{revision:int,source:string,target:string}>>
     */
    public function forListings(string $companyId, string $connectionId, array $listingIds): array
    {
        Assert::maxCount($listingIds, 100);
        if ([] === $listingIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative('SELECT r.listing_id, r.revision, r.source_fingerprint, r.target_fingerprint
            FROM moysklad_mapping_package_reviews r
            WHERE r.company_id=:company AND r.connection_id=:connection AND r.listing_id IN (:ids)
                AND r.revision=(SELECT MAX(m.revision) FROM moysklad_listing_mapping_revisions m
                    WHERE m.company_id=r.company_id AND m.connection_id=r.connection_id AND m.listing_id=r.listing_id)',
            ['company' => $companyId, 'connection' => $connectionId, 'ids' => $listingIds], ['ids' => ArrayParameterType::STRING]);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['listing_id']][] = ['revision' => (int) $row['revision'], 'source' => (string) $row['source_fingerprint'], 'target' => (string) $row['target_fingerprint']];
        }

        return $result;
    }
}
