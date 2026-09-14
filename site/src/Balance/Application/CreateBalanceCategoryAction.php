<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\CreateBalanceCategoryCommand;
use App\Balance\Security\BalanceAccess;

final readonly class CreateBalanceCategoryAction
{
    public function __construct(private BalanceStructureService $structure, private BalanceAccess $access)
    {
    }

    public function __invoke(string $companyId, CreateBalanceCategoryCommand $command): string
    {
        return $this->structure->saveCategory($companyId, $this->access->actor($companyId, 'manage'), $command->name, $command->type, $command->parentId, $command->code, $command->kind, null, $command->isVisible);
    }
}
