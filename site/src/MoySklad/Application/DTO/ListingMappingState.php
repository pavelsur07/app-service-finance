<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Enum\ListingMappingOperation;

final readonly class ListingMappingState
{
    /** @param array<string,mixed>|null $listingSnapshot */
    public function __construct(
        public int $revision = 0, public ?string $revisionId = null, public ?CatalogTargetDTO $target = null,
        public string $status = 'unmapped', public ?string $reason = null, public ?array $listingSnapshot = null,
    ) {
    }

    public static function fromRevision(MappingRevisionDTO $revision, ?CatalogTargetDTO $target, bool $listingAvailable = true): self
    {
        if (ListingMappingOperation::CLEAR === $revision->operation) {
            return new self($revision->revision, $revision->id, listingSnapshot: $revision->listingSnapshot);
        }
        $reason = !$listingAvailable ? 'listing_unavailable' : (null === $target ? 'target_unavailable' : $target->reason);

        return new self($revision->revision, $revision->id, $target, null === $reason ? 'mapped' : 'needs_review', $reason, $revision->listingSnapshot);
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'mapped' => 'Сопоставлен', 'needs_review' => 'Требует проверки', default => 'Не сопоставлен',
        };
    }

    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            'package_review' => 'Проверить комплектацию',
            'listing_unavailable' => 'Листинг недоступен',
            'archived' => 'Позиция в архиве',
            'parent_archived' => 'Товар модификации в архиве',
            'requires_variant' => 'Выберите конкретную модификацию',
            default => 'Проверьте связь с номенклатурой',
        };
    }
}
