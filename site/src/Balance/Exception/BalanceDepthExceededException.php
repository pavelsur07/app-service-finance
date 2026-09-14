<?php

declare(strict_types=1);

namespace App\Balance\Exception;

final class BalanceDepthExceededException extends BalanceLedgerException
{
    public function __construct(int $maxLevel = 4)
    {
        parent::__construct(sprintf('Максимальная вложенность категорий — %d уровней.', $maxLevel));
    }
}
