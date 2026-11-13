<?php

declare(strict_types=1);

namespace App\Cash\Application\AutoRule;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Message\EnqueueAutoRulesForRange;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EnqueueCashAutoRulesAction
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /** @return array{from: \DateTimeImmutable, to: \DateTimeImmutable} */
    public function __invoke(string $companyId, CashTransactionAutoRuleApplyMode $mode, ?string $actorId): array
    {
        if ($mode->replacesAutoAssigned() && null === $actorId) {
            throw new \DomainException('Для замены значений нужен инициатор.');
        }
        $today = new \DateTimeImmutable('today');
        $from = $today->setDate((int) $today->format('Y'), 1, 1);
        $this->bus->dispatch(new EnqueueAutoRulesForRange($companyId, $from, $today, null, Uuid::uuid7()->toString(), $mode, $actorId));

        return ['from' => $from, 'to' => $today];
    }
}
