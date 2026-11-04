<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\FillBudgetCellsCommand;
use App\Budget\Application\Service\BudgetFillPlanner;
use App\Budget\Application\Service\BudgetLinesWriter;
use App\Budget\Application\Service\BudgetRuleCalculator;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Application\View\BudgetFillPlan;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetFillOverwriteUnconfirmedException;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Помощник заполнения: предпросмотр или запись. Точка входа, коммитит сама.
 *
 * Запись: под блокировкой варианта ({@see BudgetVersionLock}) в одной транзакции перечитывается
 * статус, заново считается план помощника по текущим ячейкам (sales_plan, комментарии,
 * перезапись) и пишется BudgetLinesWriter — между проверкой и записью другая правка не вклинится;
 * правила «% от строки» пары пересчитываются там же.
 * Повтор того же запроса считает те же суммы: ячейки уже такие — менять нечего.
 */
final class FillBudgetCellsAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetFillPlanner $planner,
        private readonly BudgetLinesWriter $writer,
        private readonly BudgetRuleCalculator $rules,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FillBudgetCellsCommand $command): BudgetFillPlan
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        if (!$command->apply) {
            return $this->planner->plan($version, $command->request);
        }

        /** @var array{BudgetFillPlan|null, BudgetVersionStatus|null} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($command, $version): array {
            $connection = $this->entityManager->getConnection();
            BudgetVersionLock::acquire($connection, $version->getId());
            $status = BudgetVersionStatus::from((string) $connection->fetchOne(
                'SELECT status FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $version->getId(), 'companyId' => $command->companyId],
            ));
            if (BudgetVersionStatus::DRAFT !== $status) {
                return [null, $status];
            }

            $plan = $this->planner->plan($version, $command->request);
            if ([] === $plan->cells || ($plan->overwrite > 0 && !$command->confirmOverwrite)) {
                return [$plan, null];
            }
            $request = $command->request;
            $this->writer->write($command->companyId, $version->getId(), $this->writer->validate(
                $command->companyId,
                $version->getId(),
                $version->getYear(),
                array_map(
                    static fn (BudgetFillCell $cell): BudgetCellInput => new BudgetCellInput($cell->plCategoryId, $cell->month, $request->projectDirectionId, $request->responsibilityCenterId, $cell->amount, $cell->comment),
                    $plan->cells,
                ),
            ));
            $this->rules->recalculate($command->companyId, $version->getId(), $version->getYear(), $request->projectDirectionId, $request->responsibilityCenterId);

            return [$plan, null];
        });
        [$plan, $status] = $result;
        if (null !== $status) {
            throw new BudgetVersionNotEditableException($status);
        }
        \assert(null !== $plan);
        if ($plan->overwrite > 0 && !$command->confirmOverwrite) {
            throw new BudgetFillOverwriteUnconfirmedException($plan->overwrite);
        }

        $this->logger->info('Budget: помощник заполнил ячейки плана', [
            'companyId' => $command->companyId,
            'versionId' => $version->getId(),
            'helper' => $command->request->helper->value,
            'cells' => $plan->changed,
        ]);

        return $plan;
    }
}
