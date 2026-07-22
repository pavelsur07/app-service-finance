<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Единственная точка сохранения автоправила ДДС: проверка пары проект/ЦФО,
 * валидация сущности, аудит-штамп и flush.
 * Используется и HTTP-контроллером, и MCP-инструментом, поэтому не зависит от Request/формы.
 */
final class SaveCashTransactionAutoRuleAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly CashTransactionAutoRuleTargetValidator $targetValidator,
    ) {
    }

    public function __invoke(
        CashTransactionAutoRule $rule,
        ?string $actorUserId = null,
        ?string $currentProjectDirectionId = null,
        ?string $currentResponsibilityCenterId = null,
    ): void {
        $this->targetValidator->assertValidChange(
            (string) $rule->getCompany()->getId(),
            $currentProjectDirectionId,
            $currentResponsibilityCenterId,
            $rule->getProjectDirection()?->getId(),
            $rule->getResponsibilityCenterId(),
        );

        $violations = $this->validator->validate($rule);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            throw new \DomainException(implode('; ', $messages));
        }

        if (!$this->entityManager->contains($rule)) {
            $this->entityManager->persist($rule);
            $this->entityManager->flush();

            return;
        }

        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $hasChanges = [] !== $unitOfWork->getEntityChangeSet($rule)
            || $rule->getConditions()->exists(
                static fn ($key, $condition): bool => [] !== $unitOfWork->getEntityChangeSet($condition),
            );

        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $hasChanges = $hasChanges || $collection->getOwner() === $rule;
        }

        if ($hasChanges) {
            $rule->recordUpdate($actorUserId);
            $unitOfWork->recomputeSingleEntityChangeSet(
                $this->entityManager->getClassMetadata(CashTransactionAutoRule::class),
                $rule,
            );
        }

        $this->entityManager->flush();
    }
}
