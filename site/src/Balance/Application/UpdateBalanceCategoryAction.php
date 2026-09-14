<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\UpdateBalanceCategoryCommand;
use App\Balance\Security\BalanceAccess;

final readonly class UpdateBalanceCategoryAction
{
    public function __construct(private BalanceStructureService $structure, private BalanceAccess $access)
    {
    }

    public function __invoke(string $companyId, UpdateBalanceCategoryCommand $command): void
    {
        $this->structure->saveCategory($companyId, $this->access->actor($companyId, 'manage'), $command->name, $command->type, $command->parentId, $command->code, $command->kind, $command->id, $command->isVisible);
    }
}
