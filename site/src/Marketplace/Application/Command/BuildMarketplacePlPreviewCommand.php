<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PlPreviewColumnMode;

final readonly class BuildMarketplacePlPreviewCommand
{
    /**
     * @param list<string>|null $tagIds null — все теги компании; список — только эти колонки
     *                                  (чужие и несуществующие id отбрасываются)
     * @param list<string>|null $projectIds то же для проектов ОПиУ в режиме PROJECTS
     */
    public function __construct(
        public string $companyId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?MarketplaceType $marketplace = null,
        public ?array $tagIds = null,
        public PlPreviewColumnMode $mode = PlPreviewColumnMode::TAGS,
        public ?array $projectIds = null,
    ) {
    }
}
