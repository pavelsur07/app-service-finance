<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\FillBudgetCellsCommand;
use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Application\Service\BudgetFillPlanner;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Exception\BudgetFillOverwriteUnconfirmedException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use Webmozart\Assert\Assert;

/**
 * Помощник заполнения: предпросмотр или запись. Запись — одним вызовом SaveBudgetLinesAction:
 * его транзакция, блокировка варианта и проверка статуса; повтор — upsert тех же ячеек.
 */
final class FillBudgetCellsAction
{
    public function __construct(
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetFillPlanner $planner,
        private readonly SaveBudgetLinesAction $saveLines,
    ) {
    }

    public function __invoke(FillBudgetCellsCommand $command): BudgetFillPlan
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();

        $plan = $this->planner->plan($version, $command->request);
        if (!$command->apply || [] === $plan->cells) {
            return $plan;
        }
        if ($plan->overwrite > 0 && !$command->confirmOverwrite) {
            throw new BudgetFillOverwriteUnconfirmedException($plan->overwrite);
        }

        $request = $command->request;
        ($this->saveLines)(new SaveBudgetLinesCommand($command->companyId, $command->versionId, array_map(
            static fn (BudgetFillCell $cell): BudgetCellInput => new BudgetCellInput($cell->plCategoryId, $cell->month, $request->projectDirectionId, $request->responsibilityCenterId, $cell->amount, $cell->comment),
            $plan->cells,
        )));

        return $plan;
    }
}
