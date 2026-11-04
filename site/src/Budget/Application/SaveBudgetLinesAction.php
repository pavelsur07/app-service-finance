<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Application\DTO\SaveBudgetLinesCommand;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Entity\BudgetLine;
use App\Budget\Entity\BudgetVersion;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Repository\BudgetVersionRepository;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Сохраняет пачку ячеек черновика: upsert по ячейке, ноль без комментария удаляет строку.
 *
 * Пачка принимается целиком или отклоняется целиком: листовая статья ОПиУ своей компании,
 * активная пара «проект × ЦФО», месяц года варианта, сумма NUMERIC(18,2). Повтор той же пачки
 * даёт те же строки. Одна и та же ячейка в пачке — побеждает последняя.
 *
 * Точка входа, коммитит сама; под блокировкой варианта ({@see BudgetVersionLock}) статус
 * перечитывается — в параллельно утверждённый вариант ничего не пишется. Только DBAL.
 */
final class SaveBudgetLinesAction
{
    public const MAX_CELLS = 5000;
    private const CHUNK = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly PLCategoryFacade $plCategories,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenters,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int число сохранённых ячеек (с удалёнными нулевыми)
     */
    public function __invoke(SaveBudgetLinesCommand $command): int
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);

        if (\count($command->cells) > self::MAX_CELLS) {
            throw new InvalidBudgetInputException(sprintf('За один раз — не больше %d ячеек.', self::MAX_CELLS));
        }
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        if ([] === $command->cells) {
            return 0;
        }

        $cells = $this->validate($command, $version);

        $status = $this->entityManager->wrapInTransaction(function () use ($command, $version, $cells): ?BudgetVersionStatus {
            $connection = $this->entityManager->getConnection();
            BudgetVersionLock::acquire($connection, $version->getId());
            $status = BudgetVersionStatus::from((string) $connection->fetchOne(
                'SELECT status FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $command->versionId, 'companyId' => $command->companyId],
            ));
            if (BudgetVersionStatus::DRAFT !== $status) {
                return $status;
            }
            $this->write($command, $cells);

            return null;
        });
        if (null !== $status) {
            throw new BudgetVersionNotEditableException($status);
        }

        $this->logger->info('Budget: ячейки плана сохранены', [
            'companyId' => $command->companyId,
            'versionId' => $command->versionId,
            'cells' => \count($cells),
        ]);

        return \count($cells);
    }

    /**
     * @return array<string, array{category: string, month: string, project: string, center: string, amount: string, comment: ?string}> ключ — ячейка
     */
    private function validate(SaveBudgetLinesCommand $command, BudgetVersion $version): array
    {
        $leafIds = [];
        foreach ($this->plCategories->getTreeByCompanyId($command->companyId) as $category) {
            if (PLCategoryType::LEAF_INPUT === $category->type) {
                $leafIds[$category->id] = true;
            }
        }
        $pairs = [];
        foreach ($this->responsibilityCenters->getActivePairs($command->companyId) as $pair) {
            $pairs[$pair->projectDirectionId.'|'.$pair->responsibilityCenterId] = true;
        }

        $cells = [];
        foreach ($command->cells as $index => $cell) {
            $error = $this->cellError($cell, $version->getYear(), $leafIds, $pairs);
            if (null !== $error) {
                throw new InvalidBudgetInputException(sprintf('Ячейка %d: %s', $index + 1, $error));
            }
            $key = implode('|', [$cell->plCategoryId, $cell->month, $cell->projectDirectionId, $cell->responsibilityCenterId]);
            unset($cells[$key]);
            $cells[$key] = [
                'category' => $cell->plCategoryId,
                'month' => $cell->month.'-01',
                'project' => $cell->projectDirectionId,
                'center' => $cell->responsibilityCenterId,
                'amount' => $cell->amount,
                'comment' => BudgetLine::normalizeComment($cell->comment),
            ];
        }

        return $cells;
    }

    /**
     * @param array<string, true> $leafIds
     * @param array<string, true> $pairs
     */
    private function cellError(BudgetCellInput $cell, int $year, array $leafIds, array $pairs): ?string
    {
        if (!isset($leafIds[$cell->plCategoryId])) {
            return 'статья не найдена или не листовая.';
        }
        if (!isset($pairs[$cell->projectDirectionId.'|'.$cell->responsibilityCenterId])) {
            return 'пара «проект × ЦФО» не найдена или не активна.';
        }
        if (1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $cell->month, $match) || (int) $match[1] !== $year) {
            return sprintf('месяц должен быть в %d году.', $year);
        }
        if (!BudgetAmount::isValid($cell->amount)) {
            return 'сумма — число с точкой, до двух знаков после неё.';
        }
        if (null !== $cell->comment && mb_strlen(trim($cell->comment)) > BudgetLine::COMMENT_MAX_LENGTH) {
            return 'комментарий — не длиннее 500 символов.';
        }

        return null;
    }

    /**
     * @param array<string, array{category: string, month: string, project: string, center: string, amount: string, comment: ?string}> $cells
     */
    private function write(SaveBudgetLinesCommand $command, array $cells): void
    {
        $connection = $this->entityManager->getConnection();
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $deleted = [];
        $upserted = [];
        foreach ($cells as $cell) {
            if (BudgetAmount::isZero($cell['amount']) && null === $cell['comment']) {
                $deleted[] = $cell;
            } else {
                $upserted[] = $cell;
            }
        }

        foreach (array_chunk($deleted, self::CHUNK) as $chunk) {
            $conditions = [];
            $params = [$command->versionId, $command->companyId];
            foreach ($chunk as $cell) {
                $conditions[] = '(pl_category_id = ? AND month = ? AND project_direction_id = ? AND responsibility_center_id = ?)';
                array_push($params, $cell['category'], $cell['month'], $cell['project'], $cell['center']);
            }
            $connection->executeStatement(
                'DELETE FROM budget_lines WHERE version_id = ? AND company_id = ? AND ('.implode(' OR ', $conditions).')',
                $params,
            );
        }

        foreach (array_chunk($upserted, self::CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $cell) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    Uuid::uuid7()->toString(),
                    $command->companyId,
                    $command->versionId,
                    $cell['category'],
                    $cell['month'],
                    $cell['project'],
                    $cell['center'],
                    $cell['amount'],
                    BudgetLineSource::MANUAL->value,
                    $cell['comment'],
                    $now,
                );
            }
            $connection->executeStatement(
                'INSERT INTO budget_lines (id, company_id, version_id, pl_category_id, month, project_direction_id, responsibility_center_id, amount, source, comment, updated_at)
                 VALUES '.implode(', ', $values).'
                 ON CONFLICT (version_id, pl_category_id, month, project_direction_id, responsibility_center_id)
                 DO UPDATE SET amount = EXCLUDED.amount, source = EXCLUDED.source, comment = EXCLUDED.comment, updated_at = EXCLUDED.updated_at',
                $params,
            );
        }

        $connection->executeStatement(
            'UPDATE budget_versions SET updated_at = ? WHERE id = ? AND company_id = ?',
            [$now, $command->versionId, $command->companyId],
        );
    }
}
