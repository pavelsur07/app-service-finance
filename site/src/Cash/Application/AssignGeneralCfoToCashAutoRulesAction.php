<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AssignGeneralCfoToCashAutoRulesAction
{
    public function __construct(
        private readonly CashTransactionAutoRuleRepository $ruleRepository,
        private readonly FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
        private readonly CompanyFacade $companyFacade,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{companies: int, candidates: int, assignable: int, blocked: int, updated: int}
     */
    public function __invoke(bool $execute, ?string $actorUserId = null, ?int $expectedCount = null): array
    {
        if ($execute && (null === $actorUserId || !Uuid::isValid($actorUserId))) {
            throw new \InvalidArgumentException('Для --execute укажите UUID согласовавшего пользователя в --actor-user-id.');
        }
        if ($execute && !$this->companyFacade->userExists($actorUserId)) {
            throw new \InvalidArgumentException('Пользователь из --actor-user-id не найден.');
        }
        if ($execute && (null === $expectedCount || $expectedCount < 0)) {
            throw new \InvalidArgumentException('Для --execute укажите неотрицательное число из dry-run в --expected-count.');
        }

        if (!$execute) {
            return $this->process(false, null);
        }

        return $this->entityManager->wrapInTransaction(
            fn (): array => $this->process(true, $actorUserId, $expectedCount),
        );
    }

    /**
     * @return array{companies: int, candidates: int, assignable: int, blocked: int, updated: int}
     */
    private function process(bool $execute, ?string $actorUserId, ?int $expectedCount = null): array
    {
        $rules = $this->ruleRepository->findActiveGeneralProjectTargetsWithoutCfo();
        if ($execute && $expectedCount !== count($rules)) {
            throw new \DomainException(sprintf('Число кандидатов изменилось: ожидалось %d, найдено %d. Повторите dry-run.', $expectedCount, count($rules)));
        }
        $companyIds = [];
        /** @var array<string, FinancialResponsibilityCenterProjectDTO|null> $pairsByCompany */
        $pairsByCompany = [];
        /** @var list<array{CashTransactionAutoRule, string}> $targets */
        $targets = [];
        $blocked = 0;

        foreach ($rules as $rule) {
            $companyId = (string) $rule->getCompany()->getId();
            $companyIds[$companyId] = true;
            if (!array_key_exists($companyId, $pairsByCompany)) {
                $pairsByCompany[$companyId] = $this->responsibilityCenterFacade->findGeneralPair($companyId);
            }

            $pair = $pairsByCompany[$companyId];
            if (null === $pair || $pair->projectDirectionId !== $rule->getProjectDirection()?->getId()) {
                ++$blocked;

                continue;
            }

            $targets[] = [$rule, $pair->responsibilityCenterId];
        }

        if ($execute && 0 === $blocked) {
            foreach ($targets as [$rule, $responsibilityCenterId]) {
                $rule
                    ->setResponsibilityCenterId($responsibilityCenterId)
                    ->recordUpdate($actorUserId);
            }
        }

        return [
            'companies' => count($companyIds),
            'candidates' => count($rules),
            'assignable' => count($targets),
            'blocked' => $blocked,
            'updated' => $execute && 0 === $blocked ? count($targets) : 0,
        ];
    }
}
