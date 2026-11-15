<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

use App\MoySklad\Application\DTO\AutomaticMappingGuard;
use App\MoySklad\Enum\ListingMappingOperation;
use Webmozart\Assert\Assert;

final readonly class ChangeListingMappingCommand
{
    public function __construct(
        public string $companyId, public string $actorUserId, public string $connectionId,
        public string $listingId, public ListingMappingOperation $operation,
        public ?string $targetType, public ?string $targetExternalId, public int $expectedRevision, public string $requestId, public ?AutomaticMappingGuard $automaticGuard = null,
    ) {
        foreach ([$companyId, $actorUserId, $connectionId, $listingId, $requestId] as $uuid) {
            Assert::regex($uuid, '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD');
        }
        Assert::greaterThanEq($expectedRevision, 0);
        if (null !== $automaticGuard) {
            Assert::same($operation, ListingMappingOperation::SET);
            Assert::same($expectedRevision, 0);
        }
        if (ListingMappingOperation::SET === $operation) {
            Assert::inArray($targetType, ['product', 'variant']);
            Assert::string($targetExternalId);
            Assert::regex($targetExternalId, '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD');
        } else {
            Assert::null($targetType);
            Assert::null($targetExternalId);
        }
    }
}
