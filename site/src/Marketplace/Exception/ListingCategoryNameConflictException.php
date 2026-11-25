<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingCategoryNameConflictException extends \RuntimeException
{
    public function __construct(string $name, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Категория «%s» уже существует.', $name), 0, $previous);
    }
}
