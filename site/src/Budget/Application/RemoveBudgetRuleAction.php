<?php

declare(strict_types=1);

namespace App\Budget\Application;

use App\Budget\Application\DTO\RemoveBudgetRuleCommand;
use App\Budget\Application\Service\BudgetPairResolver;
use App\Budget\Enum\BudgetLineSource;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetVersionNotEditableException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\InvalidBudgetPairException;
use App\Budget\Repository\BudgetVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Снимает правило статьи в паре черновика: суммы остаются, источник `rule` → `manual`. Точка
 * входа, коммитит сама, под блокировкой варианта. Нет правила — ничего не меняется (повтор безопасен).
 */
final class RemoveBudgetRuleAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetVersionRepository $versions,
        private readonly BudgetPairResolver $pairs,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RemoveBudgetRuleCommand $command): bool
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

        /** @var bool|BudgetVersionStatus $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($command, $version, $pair): bool|BudgetVersionStatus {
            $connection = $this->entityManager->getConnection();
            BudgetVersionLock::acquire($connection, $version->getId());
            $status = BudgetVersionStatus::from((string) $connection->fetchOne(
                'SELECT status FROM budget_versions WHERE id = :id AND company_id = :companyId',
                ['id' => $version->getId(), 'companyId' => $command->companyId],
            ));
            if (BudgetVersionStatus::DRAFT !== $status) {
                return $status;
            }
            $params = [
                'companyId' => $command->companyId,
                'versionId' => $version->getId(),
                'categoryId' => $command->plCategoryId,
                'projectId' => $pair->projectDirectionId,
                'centerId' => $pair->responsibilityCenterId,
            ];
            $deleted = $connection->executeStatement(
                'DELETE FROM budget_rules
                  WHERE company_id = :companyId AND version_id = :versionId AND pl_category_id = :categoryId
                    AND project_direction_id = :projectId AND responsibility_center_id = :centerId',
                $params,
            );
            if (0 === $deleted) {
                return false;
            }
            $connection->executeStatement(
                'UPDATE budget_lines SET source = :manual
                  WHERE company_id = :companyId AND version_id = :versionId AND pl_category_id = :categoryId
                    AND project_direction_id = :projectId AND responsibility_center_id = :centerId AND source = :rule',
                $params + ['manual' => BudgetLineSource::MANUAL->value, 'rule' => BudgetLineSource::RULE->value],
            );
            $connection->executeStatement(
                'UPDATE budget_versions SET updated_at = :now WHERE id = :versionId AND company_id = :companyId',
                ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'versionId' => $version->getId(), 'companyId' => $command->companyId],
            );

            return true;
        });
        if ($result instanceof BudgetVersionStatus) {
            throw new BudgetVersionNotEditableException($result);
        }
        $removed = $result;

        if ($removed) {
            $this->logger->info('Budget: правило «% от строки» снято', ['companyId' => $command->companyId, 'versionId' => $version->getId()]);
        }

        return $removed;
    }
}
