<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

use App\MoySklad\Application\DTO\AutomaticMappingPreview;
use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use Webmozart\Assert\Assert;

final readonly class ApplyAutomaticMappingsCommand
{
    /** @param list<string> $selectedIds */
    public function __construct(public string $companyId, public string $actorUserId, public string $connectionId, public string $marketplace,
        public AutomaticMappingPreview $preview, public array $selectedIds)
    {
        Assert::same([$companyId, $actorUserId, $connectionId, $marketplace], [$preview->companyId, $preview->actorUserId, $preview->connectionId, $preview->marketplace]);
        Assert::isList($selectedIds);
        Assert::minCount($selectedIds, 1);
        Assert::maxCount($selectedIds, 25);
        Assert::same(count($selectedIds), count(array_unique($selectedIds)));
        $ready = [];
        foreach ($preview->items as $item) {
            Assert::same($item->source->marketplace, $marketplace);
            if (AutomaticMappingDecisionStatus::READY === $item->decision->status) {
                $ready[] = $item->listingId;
            }
        }
        foreach ($selectedIds as $id) {
            Assert::uuid($id);
            Assert::inArray($id, $ready);
        }
    }
}
