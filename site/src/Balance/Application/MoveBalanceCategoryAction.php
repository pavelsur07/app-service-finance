<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Security\BalanceAccess;

final readonly class MoveBalanceCategoryAction
{
    public function __construct(private BalanceStructureService $structure, private BalanceAccess $access)
    {
    }

    public function __invoke(string $companyId, string $categoryId, string $direction): void
    {
        $this->structure->moveCategory($companyId, $this->access->actor($companyId, 'manage'), $categoryId, $direction);
    }
}
