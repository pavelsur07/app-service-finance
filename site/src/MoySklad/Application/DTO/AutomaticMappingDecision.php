<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\AutomaticMappingDecisionStatus;
use Webmozart\Assert\Assert;

final readonly class AutomaticMappingDecision
{
    public string $reason;

    /** @param list<string> $matchedRules */
    public function __construct(
        public AutomaticMappingDecisionStatus $status,
        public ?CatalogTargetDTO $target = null,
        public array $matchedRules = [],
        public ?string $comparedSize = null,
        public ?string $selectedCharacteristicId = null,
    ) {
        Assert::true((AutomaticMappingDecisionStatus::READY === $status) === (null !== $target));
        $this->reason = $status->label();
    }
}
