<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Безвозвратное удаление автоправила ДДС: условия уходят каскадом.
 *
 * Doctrine-подписчик аудита слушает только postPersist/postUpdate, поэтому запись
 * об удалении делается здесь явно — иначе правило исчезало бы бесследно, и на
 * вопрос «почему у операции такая статья» ответить было бы нечем.
 */
final class DeleteCashTransactionAutoRuleAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(CashTransactionAutoRule $rule, ?string $actorUserId = null): void
    {
        $this->entityManager->persist(new AuditLog(
            (string) $rule->getCompany()->getId(),
            CashTransactionAutoRule::class,
            (string) $rule->getId(),
            AuditLogAction::DELETE,
            [
                'name' => $rule->getName(),
                'priority' => $rule->getPriority(),
                'isActive' => $rule->isActive(),
                'cashflowCategoryId' => $rule->getCashflowCategory()?->getId(),
                'conditionCount' => $rule->getConditions()->count(),
            ],
            $actorUserId,
        ));

        $this->entityManager->remove($rule);
        $this->entityManager->flush();
    }
}
