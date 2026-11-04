<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\SetBudgetRuleCommand;
use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Application\Service\BudgetRuleCalculator;
use App\Budget\Application\Service\BudgetTreeCalculator;
use App\Budget\Domain\BudgetFillMath;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetInputException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Infrastructure\Query\BudgetRulesQuery;
use App\Budget\Repository\BudgetVersionRepository;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLValueFormat;
use App\Finance\Facade\PLCategoryFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Задаёт (или меняет) правило «X % от строки-базы» статьи в паре черновика и сразу пересчитывает
 * её суммы. Точка входа, коммитит сама.
 *
 * Цель — лист ввода; база — денежная строка дерева (лист или итог), не сама цель. Под
 * блокировкой варианта: статус — черновик; база не считается по правилу этой пары, а цель не служит
 * базой другому правилу (цепочек нет). Upsert по `uniq_budget_rules_target`; повтор — те же строки.
 * Отказ под блокировкой возвращается из транзакции, а не бросается в ней: исключение внутри
 * wrapInTransaction закрыло бы EntityManager.
 */
final class SetBudgetRuleAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly PLCategoryFacade $categories,
        private readonly BudgetTreeCalculator $tree,
        private readonly BudgetRulesQuery $rules,
        private readonly BudgetRuleCalculator $calculator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int число пересчитанных ячеек
     */
    public function __invoke(SetBudgetRuleCommand $command): int
    {
        Assert::uuid($command->companyId);
        Assert::uuid($command->versionId);
        $version = $this->versions->findOneByIdAndCompanyId($command->versionId, $command->companyId)
            ?? throw new BudgetVersionNotFoundException();
        if (!$version->isEditable()) {
            throw new BudgetVersionNotEditableException($version->getStatus());
        }
        $pair = $this->pairs->resolve($command->companyId, $command->projectDirectionId, $command->responsibilityCenterId)
            ?? throw new InvalidBudgetPairException();
        if (!BudgetFillMath::isPercent($command->percent) || 0 === bccomp($command->percent, '0', 2)) {
            throw new InvalidBudgetInputException(sprintf('Процент — число больше 0 и не больше %s, до двух знаков после точки.', BudgetFillMath::MAX_PERCENT));
        }
        $target = null;
        foreach ($this->categories->getTreeByCompanyId($command->companyId) as $category) {
            if ($category->id === $command->plCategoryId && PLCategoryType::LEAF_INPUT === $category->type) {
                $target = $category;
            }
        }
        if (null === $target) {
            throw new InvalidBudgetInputException('Статья не найдена или в неё нельзя вводить план.');
        }
        if ($command->baseCategoryId === $command->plCategoryId) {
            throw new InvalidBudgetInputException('Статья не может считаться от самой себя.');
        }
        $base = $this->tree->calculate($command->companyId, $version->getYear(), [])->byId()[$command->baseCategoryId] ?? null;
        if (null === $base || PLValueFormat::MONEY !== $base->row->format) {
            throw new InvalidBudgetInputException('База — денежная строка ОПиУ: статья или итог.');
        }

        /** @var int|string|BudgetVersionStatus $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($command, $version, $pair): int|string|BudgetVersionStatus {
            $connection = $this->entityManager->getConnection();
            BudgetVersionLock::acquire($connection, $version->getId());
            $status = BudgetVersionStatus::from((string) $connection->fetchOne(
                'SELECT status FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $version->getId(), 'companyId' => $command->companyId],
            ));
            if (BudgetVersionStatus::DRAFT !== $status) {
                return $status;
            }

            $existing = $this->rules->targetsOfPair($command->companyId, $version->getId(), $pair->projectDirectionId, $pair->responsibilityCenterId);
            if (isset($existing[$command->baseCategoryId])) {
                return 'База сама считается по правилу — выберите другую строку или снимите её правило.';
            }
            foreach ($existing as $rule) {
                if ($rule->plCategoryId !== $command->plCategoryId && $rule->baseCategoryId === $command->plCategoryId) {
                    return 'От этой статьи считается другое правило — сначала снимите его.';
                }
            }

            $connection->executeStatement(
                'INSERT INTO budget_rules (id, company_id, version_id, pl_category_id, project_direction_id, responsibility_center_id, base_category_id, percent, updated_at)
                 VALUES (:id, :companyId, :versionId, :categoryId, :projectId, :centerId, :baseId, :percent, :now)
                 ON CONFLICT (version_id, pl_category_id, project_direction_id, responsibility_center_id)
                 DO UPDATE SET base_category_id = EXCLUDED.base_category_id, percent = EXCLUDED.percent, updated_at = EXCLUDED.updated_at',
                [
                    'id' => Uuid::uuid7()->toString(),
                    'companyId' => $command->companyId,
                    'versionId' => $version->getId(),
                    'categoryId' => $command->plCategoryId,
                    'projectId' => $pair->projectDirectionId,
                    'centerId' => $pair->responsibilityCenterId,
                    'baseId' => $command->baseCategoryId,
                    'percent' => $command->percent,
                    'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ],
            );

            return $this->calculator->recalculate($command->companyId, $version->getId(), $version->getYear(), $pair->projectDirectionId, $pair->responsibilityCenterId);
        });
        if ($result instanceof BudgetVersionStatus) {
            throw new BudgetVersionNotEditableException($result);
        }
        if (\is_string($result)) {
            throw new InvalidBudgetInputException($result);
        }
        $changed = $result;

        $this->logger->info('Budget: правило «% от строки» задано', [
            'companyId' => $command->companyId,
            'versionId' => $version->getId(),
            'cells' => $changed,
        ]);

        return $changed;
    }
}
