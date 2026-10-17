<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\MarketplaceType;

final readonly class BuildMarketplacePlPreviewCommand
{
    /**
     * @param list<string>|null $tagIds null — все теги компании; список — только эти колонки
     *                                  (чужие и несуществующие id отбрасываются)
     */
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace = null,
        public ?array $tagIds = null,
    ) {
    }
}
