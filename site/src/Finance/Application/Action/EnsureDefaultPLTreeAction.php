<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;

/**
 * Базовое дерево статей ОПиУ для компании без статей. Компания, у которой есть хотя бы
 * одна своя статья, не трогается: дополнить настроенное дерево шаблоном — отдельное
 * решение пользователя (экран импорта или `app:finance:pl-tree:apply-default`).
 */
final readonly class EnsureDefaultPLTreeAction
{
    public function __construct(
        private PLCategoryRepository $categories,
        private DefaultPLCategoryTreeProvider $defaultTree,
        private ImportPLCategoryTreeAction $import,
    ) {
    }

    /**
     * @return int число созданных статей
     */
    public function __invoke(string $companyId): int
    {
        if ($this->categories->existsByCompanyId($companyId)) {
            return 0;
        }

        $result = ($this->import)(new ImportPLCategoryTreeCommand(
            $this->defaultTree->nodes(),
            $companyId,
            dryRun: false,
            onlyCreate: true,
        ));

        return \count($result->created);
    }
}
