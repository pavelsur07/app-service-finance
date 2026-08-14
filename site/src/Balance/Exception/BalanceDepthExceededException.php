<?php

declare(strict_types=1);

namespace App\Balance\Exception;

final class BalanceDepthExceededException extends \DomainException
{
    public function __construct(int $maxLevel = 5)
    {
        parent::__construct(sprintf('Максимальная вложенность категорий — %d уровней.', $maxLevel));
    }
}
