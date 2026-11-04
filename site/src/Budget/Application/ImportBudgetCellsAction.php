<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\ImportBudgetCellsCommand;
use App\Budget\Application\Service\BudgetImportPlanner;
use App\Budget\Application\Service\BudgetLinesWriter;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Application\View\BudgetImportPlan;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetFillOverwriteUnconfirmedException;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Загрузка плана из файла: предпросмотр или запись. Точка входа, коммитит сама.
 *
 * Запись — как у помощников (FillBudgetCellsAction): под блокировкой варианта в одной транзакции
 * перечитывается статус, заново считается план по текущим ячейкам и пишется BudgetLinesWriter.
 * Повтор того же файла ничего не меняет: ячейки уже такие.
 */
final class ImportBudgetCellsAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetImportPlanner $planner,
        private readonly BudgetLinesWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportBudgetCellsCommand $command): BudgetImportPlan
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        if (!$command->apply) {
            return $this->planner->plan($version, $command->projectDirectionId, $command->responsibilityCenterId, $command->file);
        }

        /** @var array{BudgetImportPlan|null, BudgetVersionStatus|null} $result */
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

            $plan = $this->planner->plan($version, $command->projectDirectionId, $command->responsibilityCenterId, $command->file);
            if ([] === $plan->fill->cells || ($plan->fill->overwrite > 0 && !$command->confirmOverwrite)) {
                return [$plan, null];
            }
            $this->writer->write($command->companyId, $version->getId(), $this->writer->validate(
                $command->companyId,
                $version->getYear(),
                array_map(
                    static fn (BudgetFillCell $cell): BudgetCellInput => new BudgetCellInput($cell->plCategoryId, $cell->month, $command->projectDirectionId, $command->responsibilityCenterId, $cell->amount, $cell->comment),
                    $plan->fill->cells,
                ),
            ));

            return [$plan, null];
        });
        [$plan, $status] = $result;
        if (null !== $status) {
            throw new BudgetVersionNotEditableException($status);
        }
        \assert(null !== $plan);
        if ($plan->fill->overwrite > 0 && !$command->confirmOverwrite) {
            throw new BudgetFillOverwriteUnconfirmedException($plan->fill->overwrite);
        }

        $this->logger->info('Budget: план загружен из файла', [
            'companyId' => $command->companyId,
            'versionId' => $version->getId(),
            'cells' => $plan->fill->changed,
            'ignored' => \count($plan->ignored),
        ]);

        return $plan;
    }
}
