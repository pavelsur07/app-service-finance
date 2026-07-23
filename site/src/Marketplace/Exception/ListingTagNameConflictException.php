<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class ListingTagNameConflictException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Тег «%s» уже существует — воспользуйтесь слиянием.', $name));
    }
}
