<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingTagNotFoundException extends \RuntimeException
{
    public function __construct(string $tagId)
    {
        parent::__construct(sprintf('Тег %s не найден.', $tagId));
    }
}
