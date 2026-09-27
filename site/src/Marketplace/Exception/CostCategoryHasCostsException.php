<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class CostCategoryHasCostsException extends \RuntimeException
{
    public function __construct(string $categoryName, int $costsCount)
    {
        parent::__construct(sprintf(
            'Невозможно удалить категорию "%s": она содержит %d затрат(ы).',
            $categoryName,
            $costsCount,
        ));
    }
}
