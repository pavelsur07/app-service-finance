<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Security\BalanceAccess;

final readonly class DeleteBalanceCategoryAction
{
    public function __construct(private BalanceStructureService $structure, private BalanceAccess $access)
    {
    }

    public function __invoke(string $companyId, string $categoryId): void
    {
        $this->structure->deleteCategory($companyId, $this->access->actor($companyId, 'manage'), $categoryId);
    }
}
