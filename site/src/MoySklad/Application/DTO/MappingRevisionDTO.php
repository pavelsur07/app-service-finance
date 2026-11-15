<?php

declare(strict_types=1);

namespace App\MoySklad\Application\DTO;

use App\MoySklad\Entity\MoySkladListingMappingRevision;
use App\MoySklad\Enum\ListingMappingOperation;

final readonly class MappingRevisionDTO
{
    /** @param array<string,mixed> $listingSnapshot
     * @param array<string,mixed>|null $targetSnapshot
     */
    public function __construct(
        public string $id, public int $revision, public ListingMappingOperation $operation,
        public string $actorUserId, public \DateTimeImmutable $createdAt,
        public array $listingSnapshot, public ?array $targetSnapshot,
    ) {
    }

    public static function fromEntity(MoySkladListingMappingRevision $revision): self
    {
        return new self($revision->getId(), $revision->getRevision(), $revision->getOperation(), $revision->getActorUserId(),
            $revision->getCreatedAt(), $revision->getListingSnapshot(), $revision->getTargetSnapshot());
    }
}
