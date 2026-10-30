<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class CashCategorySuggestionNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Подсказка %s не найдена.', $id));
    }
}
