<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class ListingCategoryAssignResult
{
    /**
     * @param int $matched сколько листингов компании выбрано
     * @param int $changed у скольких категория изменилась; matched − changed — уже стояла
     */
    public function __construct(
        public int $matched,
        public int $changed,
    ) {
    }
}
