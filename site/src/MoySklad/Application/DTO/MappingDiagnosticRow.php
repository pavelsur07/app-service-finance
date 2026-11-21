<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\Marketplace\DTO\ListingMetaDTO;
use App\MoySklad\Domain\AutomaticListingMappingPolicy;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus as Status;
use App\MoySklad\Enum\MappingDiagnosticReason as Reason;

final readonly class MappingDiagnosticRow
{
    public string $listingId;
    public bool $manualReview;
    /** @var list<string> */
    public array $reasons;

    /** @param list<string> $barcodes
     * @param list<string>|null $recordedReasons
     */
    public function __construct(public ListingMetaDTO $source, public AutomaticMappingDecision $decision, public array $barcodes = [], ?array $recordedReasons = null, ?bool $recordedManualReview = null)
    {
        $this->listingId = $source->id;
        $this->manualReview = $recordedManualReview ?? !in_array($decision->status, [Status::READY, Status::EXISTING_MAPPING, Status::CLEARED_MAPPING, Status::LISTING_UNAVAILABLE], true);
        $reasons = [];
        if (null === AutomaticListingMappingPolicy::normalizeKey($source->supplierSku)) {
            $reasons[] = Reason::NO_ARTICLE->value;
        }
        if (null === AutomaticListingMappingPolicy::normalizeKey($source->title)) {
            $reasons[] = Reason::NO_NAME->value;
        }
        $reason = match ($decision->status) {
            Status::NO_MATCH, Status::NO_ARTICLE => Reason::NO_MATCH,
            Status::AMBIGUOUS, Status::TOO_MANY_CANDIDATES => Reason::DUPLICATE,
            Status::PACKAGE_REVIEW => Reason::PACKAGE,
            Status::SIZE_RULE_REQUIRED, Status::SIZE_UNKNOWN, Status::SIZE_MISMATCH, Status::SIZE_UNVERIFIABLE, Status::VARIANT_REQUIRED => Reason::SIZE,
            Status::TARGET_UNAVAILABLE, Status::CATALOG_NOT_READY, Status::LISTING_UNAVAILABLE => Reason::UNAVAILABLE,
            Status::CLEARED_MAPPING => Reason::CLEARED,
            Status::BARCODE_CONFLICT => Reason::BARCODE,
            default => null,
        };
        if (null !== $reason) {
            $reasons[] = $reason->value;
        }
        $unresolved = !in_array($decision->status, [Status::READY, Status::EXISTING_MAPPING, Status::CLEARED_MAPPING], true);
        if ($unresolved && count(AutomaticListingMappingPolicy::quantities($source->title)) > 1) {
            $reasons[] = Reason::PACKAGE->value;
        }
        foreach ($decision->candidates as $candidate) {
            if ($unresolved && AutomaticListingMappingPolicy::packageNeedsReview($source->title, $candidate->target->name)) {
                $reasons[] = Reason::PACKAGE->value;
            }
            if (in_array($candidate->target->reason, ['archived', 'parent_archived'], true)) {
                $reasons[] = Reason::ARCHIVED->value;
            }
        }
        $this->reasons = $recordedReasons ?? array_values(array_unique($reasons));
    }

    /** @return list<string> */
    public function getReasonLabels(): array
    {
        return array_map(static fn (string $reason): string => Reason::from($reason)->label(), $this->reasons);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(new ListingMetaDTO(...$data['source']), AutomaticMappingDecision::fromArray($data['decision']), $data['barcodes'], $data['reasons'], $data['manualReview']);
    }

    /** @return list<string> */
    public function getSourceQuantities(): array
    {
        return AutomaticListingMappingPolicy::quantities($this->source->title);
    }
}
