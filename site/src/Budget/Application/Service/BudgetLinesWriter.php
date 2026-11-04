<?php

declare(strict_types=1);

namespace App\Budget\Application\Service;

use App\Budget\Application\DTO\BudgetCellInput;
use App\Budget\Domain\BudgetAmount;
use App\Budget\Entity\BudgetLine;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Infrastructure\Query\BudgetRulesQuery;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Facade\PLCategoryFacade;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;

/**
 * Проверка и запись пачки ячеек плана (DBAL, без своей транзакции): upsert по ячейке, ноль без
 * комментария удаляет строку. Статья с правилом пары (BudgetRule) вручную не пишется — её суммы
 * пишет BudgetRuleCalculator с источником `rule`. Пачка принимается целиком или отклоняется целиком. Вызывающая
 * точка входа (SaveBudgetLinesAction, FillBudgetCellsAction) держит транзакцию и блокировку
 * варианта ({@see \App\Budget\Application\BudgetVersionLock}).
 */
final readonly class BudgetLinesWriter
{
    public const int MAX_CELLS = 5000;
    private const int CHUNK = 500;

    public function __construct(
        private Connection $connection,
        private PLCategoryFacade $plCategories,
        private FinancialResponsibilityCenterFacade $responsibilityCenters,
        private BudgetRulesQuery $rules,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<BudgetCellInput> $cells
     *
     * @return array<string, array{category: string, month: string, project: string, center: string, amount: string, comment: ?string}> ключ — ячейка
     */
    public function validate(string $companyId, string $versionId, int $year, array $cells): array
    {
        if (\count($cells) > self::MAX_CELLS) {
            throw new InvalidBudgetInputException(sprintf('За один раз — не больше %d ячеек.', self::MAX_CELLS));
        }

        $leafIds = [];
        foreach ($this->plCategories->getTreeByCompanyId($companyId) as $category) {
            if (PLCategoryType::LEAF_INPUT === $category->type) {
                $leafIds[$category->id] = true;
            }
        }
        $pairs = [];
        foreach ($this->responsibilityCenters->getActivePairs($companyId) as $pair) {
            $pairs[$pair->projectDirectionId.'|'.$pair->responsibilityCenterId] = true;
        }

        $ruleTargets = [];
        foreach ($this->rules->fetch($companyId, $versionId) as $rule) {
            $ruleTargets[$rule->plCategoryId.'|'.$rule->projectDirectionId.'|'.$rule->responsibilityCenterId] = true;
        }

        $result = [];
        foreach ($cells as $index => $cell) {
            $error = isset($ruleTargets[$cell->plCategoryId.'|'.$cell->projectDirectionId.'|'.$cell->responsibilityCenterId])
                ? 'статья считается по правилу «% от строки» — снимите правило, чтобы ввести сумму.'
                : $this->cellError($cell, $year, $leafIds, $pairs);
            if (null !== $error) {
                throw new InvalidBudgetInputException(sprintf('Ячейка %d: %s', $index + 1, $error));
            }
            $key = implode('|', [$cell->plCategoryId, $cell->month, $cell->projectDirectionId, $cell->responsibilityCenterId]);
            unset($result[$key]);
            $result[$key] = [
                'category' => $cell->plCategoryId,
                'month' => $cell->month.'-01',
                'project' => $cell->projectDirectionId,
                'center' => $cell->responsibilityCenterId,
                'amount' => $cell->amount,
                'comment' => BudgetLine::normalizeComment($cell->comment),
            ];
        }

        return $result;
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
    public function write(string $companyId, string $versionId, array $cells, BudgetLineSource $source = BudgetLineSource::MANUAL): void
    {
        $connection = $this->connection;
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
            $params = [$versionId, $companyId];
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
                    $companyId,
                    $versionId,
                    $cell['category'],
                    $cell['month'],
                    $cell['project'],
                    $cell['center'],
                    $cell['amount'],
                    $source->value,
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
            [$now, $versionId, $companyId],
        );
    }
}
