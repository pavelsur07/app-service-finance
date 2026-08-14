<?php

declare(strict_types=1);

namespace App\Balance\Exception;

final class BalanceCategoryCycleException extends \DomainException
{
    public function __construct(string $categoryId, string $parentId)
    {
        parent::__construct(sprintf(
            'Нельзя назначить категории %s родителя %s: образуется цикл.',
            $categoryId,
            $parentId,
        ));
    }
}
