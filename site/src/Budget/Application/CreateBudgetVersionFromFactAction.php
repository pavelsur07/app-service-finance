<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\BudgetFillRequest;
use App\Budget\Application\DTO\CreateBudgetVersionFromFactCommand;
use App\Budget\Application\Service\BudgetFillPlanner;
use App\Budget\Application\Service\BudgetLinesWriter;
use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Application\View\BudgetFactFillResult;
use App\Budget\Application\View\BudgetFillCell;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetFillHelper;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * «Заполнить из факта {год−1}»: новый черновик года, в каждой активной паре «проект × ЦФО» — факт
 * ОПиУ того же месяца прошлого года по всем листьям (тот же расчёт, что помощник
 * «Из факта прошлого года», включая вариант A для ЦФО «Общий»).
 *
 * Точка входа, коммитит сама: вариант и строки — одна транзакция, запись под блокировкой
 * варианта. Повтор с тем же `versionId` возвращает существующий вариант и ничего не пишет.
 */
final class CreateBudgetVersionFromFactAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly BudgetFillPlanner $planner,
        private readonly BudgetLinesWriter $writer,
        private readonly PLCategoryFacade $categories,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateBudgetVersionFromFactCommand $command): BudgetFactFillResult
    {
        Assert::uuid($command->versionId);
        Assert::uuid($command->companyId);

        if (null !== $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)) {
            return new BudgetFactFillResult($command->versionId, false, 0, 0);
        }
        if ($command->year < BudgetVersion::MIN_YEAR || $command->year > BudgetVersion::MAX_YEAR) {
            throw new InvalidBudgetInputException('Год бюджета — от 2000 до 2100.');
        }
        try {
            $name = BudgetVersion::normalizeName($command->name);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidBudgetInputException($exception->getMessage(), previous: $exception);
        }

        $leafIds = [];
        foreach ($this->categories->getTreeByCompanyId($command->companyId) as $category) {
            if (PLCategoryType::LEAF_INPUT === $category->type) {
                $leafIds[] = $category->id;
            }
        }
        $pairs = $this->pairs->options($command->companyId);

        try {
            /** @var array{int, int} $written */
            $written = $this->entityManager->wrapInTransaction(function () use ($command, $name, $leafIds, $pairs): array {
                $version = new BudgetVersion($command->versionId, $command->companyId, $command->year, $name, $this->clock->now());
                $this->entityManager->persist($version);
                // Строки ссылаются на вариант внешним ключом — сначала он.
                $this->entityManager->flush();
                BudgetVersionLock::acquire($this->entityManager->getConnection(), $version->getId());
                if ([] === $leafIds) {
                    return [0, 0];
                }

                $cells = 0;
                $filledPairs = 0;
                foreach ($pairs as $pair) {
                    $project = $pair['projectDirectionId'];
                    $center = $pair['responsibilityCenterId'];
                    $plan = $this->planner->plan($version, new BudgetFillRequest(BudgetFillHelper::LAST_YEAR_FACT, $project, $center, $leafIds, [], []));
                    if ([] === $plan->cells) {
                        continue;
                    }
                    foreach (array_chunk($plan->cells, BudgetLinesWriter::MAX_CELLS) as $chunk) {
                        $this->writer->write($command->companyId, $version->getId(), $this->writer->validate(
                            $command->companyId,
                            $version->getId(),
                            $command->year,
                            array_map(
                                static fn (BudgetFillCell $cell): BudgetCellInput => new BudgetCellInput($cell->plCategoryId, $cell->month, $project, $center, $cell->amount, null),
                                $chunk,
                            ),
                        ));
                    }
                    $cells += \count($plan->cells);
                    ++$filledPairs;
                }

                return [$cells, $filledPairs];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Параллельный повтор: транзакция откачена, EM закрыт — проверяем DBAL.
            $exists = $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $command->versionId, 'companyId' => $command->companyId],
            );
            if (false === $exists) {
                throw $exception;
            }

            return new BudgetFactFillResult($command->versionId, false, 0, 0);
        }
        [$cells, $filledPairs] = $written;

        $this->logger->info('Budget: вариант создан из факта прошлого года', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
            'year' => $command->year,
            'cells' => $cells,
            'pairs' => $filledPairs,
        ]);

        return new BudgetFactFillResult($command->versionId, true, $cells, $filledPairs);
    }
}
