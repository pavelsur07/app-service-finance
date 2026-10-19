<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingFilterTooBroadException extends \RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf('Под фильтр попадает больше %d листингов, сузьте выборку.', $limit));
    }
}
