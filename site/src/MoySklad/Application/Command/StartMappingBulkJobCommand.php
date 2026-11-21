<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Command;

use Webmozart\Assert\Assert;

final readonly class StartMappingBulkJobCommand
{
    public const SCOPES = ['report', 'selected', 'cabinet'];

    /** @param list<string> $listingIds только для scope selected */
    public function __construct(
        public string $companyId, public string $actorUserId, public string $connectionId, public string $marketplace,
        public string $jobId, public string $scope, public array $listingIds = [], public string $origin = 'manual',
        public ?string $sizeCharacteristicId = null,
    ) {
        foreach ([$companyId, $actorUserId, $connectionId, $jobId] as $uuid) {
            Assert::uuid($uuid);
        }
        Assert::inArray($marketplace, ['ozon', 'wildberries']);
        Assert::inArray($scope, self::SCOPES);
        Assert::inArray($origin, ['manual', 'auto']);
        Assert::isList($listingIds);
        Assert::allUuid($listingIds);
        Assert::same(count($listingIds), count(array_unique($listingIds)));
        Assert::maxCount($listingIds, 100);
        Assert::same('selected' === $scope, [] !== $listingIds);
        Assert::same('cabinet' === $scope, 'auto' === $origin);
    }
}
