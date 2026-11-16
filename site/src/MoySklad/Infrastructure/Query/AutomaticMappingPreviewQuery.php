<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Application\DTO\AutomaticMappingCandidates;
use App\MoySklad\Application\DTO\AutomaticMappingDecision;
use App\MoySklad\Application\DTO\AutomaticMappingPreviewPage;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus as Status;
use App\MoySklad\Exception\ListingMappingException;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingPreviewQuery
{
    public function __construct(
        private MarketplaceFacade $marketplace, private MoySkladListingMappingsQuery $mappings,
        private MoySkladCatalogTargetsQuery $catalog, private AutomaticMappingCandidatesQuery $candidates,
        private AutomaticListingMappingPolicy $policy, private MappingCharacteristicsQuery $characteristics,
        private MappingSettingsQuery $settings,
    ) {
    }

    public function page(string $companyId, string $connectionId, string $marketplace, string $q, int $page, ?string $sizeCharacteristicId): AutomaticMappingPreviewPage
    {
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        $q = trim($q);
        if (null !== $sizeCharacteristicId && null === $this->characteristics->find($companyId, $connectionId, $sizeCharacteristicId)) {
            throw new ListingMappingException('not_found', 'Характеристика недоступна в выбранном подключении.');
        }
        $source = $this->marketplace->paginateListingsForExternalMapping($companyId, $marketplace, $q, $page, 25);
        $barcodes = $this->marketplace->getListingBarcodesByIds($companyId, array_column($source->items, 'id'));
        $settings = $this->settings->get($companyId, $connectionId, $marketplace);
        $states = $this->mappings->currentForListings($companyId, $connectionId, array_column($source->items, 'id'));
        $ready = $this->catalog->isReady($companyId, $connectionId);
        $decisions = [];
        foreach ($source->items as $listing) {
            $state = $states[$listing->id];
            $status = match (true) {
                'package_review' === $state->reason => Status::PACKAGE_REVIEW,
                $state->revision > 0 => 'unmapped' === $state->status ? Status::CLEARED_MAPPING : Status::EXISTING_MAPPING,
                !$listing->isActive => Status::LISTING_UNAVAILABLE,
                !$ready => Status::CATALOG_NOT_READY,
                default => null,
            };
            if (null !== $status) {
                $decisions[$listing->id] = new AutomaticMappingDecision($status);
            }
        }
        $groups = $this->candidates->forListings($companyId, $connectionId, array_values(array_filter($source->items, static fn ($listing): bool => !isset($decisions[$listing->id]))), $barcodes, $settings->wbTrailingW);
        foreach ($source->items as $listing) {
            if (!isset($decisions[$listing->id])) {
                $group = $groups[$listing->id] ?? new AutomaticMappingCandidates([], 0);
                $decisions[$listing->id] = $this->policy->decide($listing, $group, $sizeCharacteristicId, $barcodes[$listing->id] ?? [], $settings->wbTrailingW);
            }
        }
        $counts = array_fill_keys(array_column(Status::cases(), 'value'), 0);
        foreach ($decisions as $decision) {
            ++$counts[$decision->status->value];
        }
        $counts['total_shown'] = count($source->items);

        return new AutomaticMappingPreviewPage($source, $decisions, $counts, $q, $sizeCharacteristicId, $barcodes, $settings);
    }
}
