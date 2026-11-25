<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingCategoryNotFoundException extends \RuntimeException
{
    public function __construct(string $categoryId)
    {
        parent::__construct(sprintf('Категория %s не найдена.', $categoryId));
    }
}
