<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Включает или выключает автоправило. Сохраняет только реальную смену
 * состояния: повторное выключение ничего не пишет.
 */
final class ChangeCashTransactionAutoRuleActivityAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return bool состояние изменилось
     */
    public function __invoke(CashTransactionAutoRule $rule, bool $active, ?string $actorUserId): bool
    {
        $changed = $active ? $rule->enable($actorUserId) : $rule->disable($actorUserId);
        if ($changed) {
            $this->entityManager->flush();
        }

        return $changed;
    }
}
