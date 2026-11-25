<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingCategoryInUseException extends \RuntimeException
{
    public function __construct(public readonly int $listingCount)
    {
        parent::__construct(sprintf('Категория назначена %d листингам — сначала снимите её.', $listingCount));
    }
}
