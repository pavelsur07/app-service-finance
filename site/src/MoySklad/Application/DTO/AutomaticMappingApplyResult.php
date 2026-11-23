<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\AutomaticMappingApplyStatus;

final readonly class AutomaticMappingApplyResult
{
    public int $accepted;
    public int $conflicts;
    public int $failed;
    public int $notProcessed;

    /** @param list<AutomaticMappingApplyItem> $items */
    public function __construct(public array $items)
    {
        $counts = array_count_values(array_map(static fn (AutomaticMappingApplyItem $i): string => $i->status->value, $items));
        $this->accepted = $counts['accepted'] ?? 0;
        $this->conflicts = $counts['conflict'] ?? 0;
        $this->failed = $counts['failed'] ?? 0;
        $this->notProcessed = $counts['not_processed'] ?? 0;
    }

    /** @return list<array{listingId:string,status:string,reason:?string,revisionId:?string}> */
    public function toArray(): array
    {
        return array_map(static fn (AutomaticMappingApplyItem $i): array => ['listingId' => $i->listingId, 'status' => $i->status->value, 'reason' => $i->reason, 'revisionId' => $i->revisionId], $this->items);
    }

    /** @param list<array{listingId:string,status:string,reason:?string,revisionId:?string}> $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(static fn (array $i): AutomaticMappingApplyItem => new AutomaticMappingApplyItem($i['listingId'], AutomaticMappingApplyStatus::from($i['status']), $i['reason'], $i['revisionId']), $data));
    }
}
