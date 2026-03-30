<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class RecalculateSnapshotsRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['wildberries', 'ozon', 'yandex_market', 'sber_megamarket'])]
        public readonly string $marketplace = '',

        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly string $dateFrom = '',

        #[Assert\NotBlank]
        #[Assert\Date]
        public readonly string $dateTo = '',
    ) {}
}
