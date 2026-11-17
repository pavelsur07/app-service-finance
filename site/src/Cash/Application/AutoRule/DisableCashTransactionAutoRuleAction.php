<?php

declare(strict_types=1);

namespace App\Cash\Application\AutoRule;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Повторное выключение правила сохраняет текущую ревизию.
 */
final class DisableCashTransactionAutoRuleAction
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return bool состояние изменилось
     */
    public function __invoke(CashTransactionAutoRule $rule, ?string $actorUserId): bool
    {
        $changed = $rule->disable($actorUserId);
        if ($changed) {
            $this->entityManager->flush();
        }

        return $changed;
    }
}
