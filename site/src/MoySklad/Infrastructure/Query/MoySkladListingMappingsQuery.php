<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\DTO\ListingMappingState;
use App\MoySklad\Application\DTO\MappingHistoryPage;
use App\MoySklad\Application\DTO\MappingRevisionDTO;
use App\MoySklad\Enum\ListingMappingOperation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class MoySkladListingMappingsQuery
{
    public function __construct(private Connection $db, private MoySkladCatalogTargetsQuery $targets, private MarketplaceFacade $marketplace, private MappingPackageReviewsQuery $reviews, private \App\MoySklad\Domain\AutomaticListingMappingPolicy $policy)
    {
    }

    /** @param list<string> $listingIds
     * @return array<string,ListingMappingState>
     */
    public function currentForListings(string $companyId, string $connectionId, array $listingIds): array
    {
        Assert::maxCount($listingIds, 100);
        $companyId = Uuid::fromString($companyId)->toString();
        $connectionId = Uuid::fromString($connectionId)->toString();
        if ([] === $listingIds) {
            return [];
        }
        $listingIds = array_values(array_unique(array_map(static fn (string $id): string => Uuid::fromString($id)->toString(), $listingIds)));
        $rows = $this->db->fetchAllAssociative(
            'SELECT DISTINCT ON (listing_id) id, listing_id, revision, operation, actor_user_id, created_at, listing_snapshot, target_snapshot, product_external_id, variant_external_id FROM moysklad_listing_mapping_revisions WHERE company_id=:company AND connection_id=:connection AND listing_id IN (:ids) ORDER BY listing_id, revision DESC',
            ['company' => $companyId, 'connection' => $connectionId, 'ids' => $listingIds], ['ids' => ArrayParameterType::STRING],
        );
        $references = [];
        foreach ($rows as $row) {
            if (null !== $row['product_external_id']) {
                $references[] = 'product:'.$row['product_external_id'];
            } elseif (null !== $row['variant_external_id']) {
                $references[] = 'variant:'.$row['variant_external_id'];
            }
        }
        $targets = $this->targets->findMany($companyId, $connectionId, array_values(array_unique($references)));
        $sources = [] === $rows ? [] : $this->marketplace->getListingsMetaByIds($companyId, array_column($rows, 'listing_id'));
        $barcodes = [] === $rows ? [] : $this->marketplace->getListingBarcodesByIds($companyId, array_column($rows, 'listing_id'));
        $reviews = $this->reviews->forListings($companyId, $connectionId, $listingIds);
        $result = array_fill_keys($listingIds, new ListingMappingState());
        foreach ($rows as $row) {
            $reference = null !== $row['product_external_id'] ? 'product:'.$row['product_external_id'] : 'variant:'.$row['variant_external_id'];
            $state = ListingMappingState::fromRevision($this->revision($row), $targets[$reference] ?? null, isset($sources[$row['listing_id']]));
            $id = (string) $row['listing_id'];
            if (null === $state->reason && null !== $state->target && isset($sources[$id]) && \App\MoySklad\Domain\AutomaticListingMappingPolicy::packageNeedsReview($sources[$id]->title, $state->target->name)) {
                $sourceFingerprint = $this->policy->sourceFingerprint($sources[$id], $barcodes[$id] ?? []);
                $targetFingerprint = \App\MoySklad\Domain\AutomaticListingMappingPolicy::targetFingerprint($state->target);
                $confirmed = false;
                foreach ($reviews[$id] ?? [] as $review) {
                    $confirmed = $confirmed || ($review['revision'] === $state->revision && $review['source'] === $sourceFingerprint && $review['target'] === $targetFingerprint);
                }
                if (!$confirmed) {
                    $state = new ListingMappingState($state->revision, $state->revisionId, $state->target, 'needs_review', 'package_review', $state->listingSnapshot);
                }
            }
            $result[$id] = $state;
        }

        return $result;
    }

    public function history(string $companyId, string $connectionId, string $listingId, int $page = 1): MappingHistoryPage
    {
        Assert::greaterThanEq($page, 1);
        $companyId = Uuid::fromString($companyId)->toString();
        $connectionId = Uuid::fromString($connectionId)->toString();
        $listingId = Uuid::fromString($listingId)->toString();
        $params = ['company' => $companyId, 'connection' => $connectionId, 'listing' => $listingId];
        $sql = ' FROM moysklad_listing_mapping_revisions WHERE company_id=:company AND connection_id=:connection AND listing_id=:listing';
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$sql, $params);
        if ($page > max(1, (int) ceil($total / 20))) {
            return new MappingHistoryPage([], $page, $total);
        }
        $rows = $this->db->fetchAllAssociative('SELECT id, listing_id, revision, operation, actor_user_id, created_at, listing_snapshot, target_snapshot, product_external_id, variant_external_id'.$sql.' ORDER BY revision DESC LIMIT 20 OFFSET '.(($page - 1) * 20), $params);

        return new MappingHistoryPage(array_map($this->revision(...), $rows), $page, $total);
    }

    /** @param array<string,mixed> $row */
    private function revision(array $row): MappingRevisionDTO
    {
        /** @var array<string,mixed> $listing */
        $listing = json_decode((string) $row['listing_snapshot'], true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string,mixed>|null $target */
        $target = null === $row['target_snapshot'] ? null : json_decode((string) $row['target_snapshot'], true, 512, \JSON_THROW_ON_ERROR);

        return new MappingRevisionDTO((string) $row['id'], (int) $row['revision'], ListingMappingOperation::from($row['operation']),
            (string) $row['actor_user_id'], new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')), $listing, $target);
    }
}
