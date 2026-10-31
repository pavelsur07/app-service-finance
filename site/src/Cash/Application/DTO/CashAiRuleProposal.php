<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashDirection;

/**
 * Предложение автоправила «контрагент + направление → статья» по ИИ-подсказкам,
 * которые подтвердили люди (docs/tasks/ai/stages/stage-2.md, 2.4).
 */
final readonly class CashAiRuleProposal
{
    public function __construct(
        public string $counterpartyId,
        public string $counterpartyName,
        public CashDirection $direction,
        public string $categoryId,
        public string $categoryName,
        public int $confirmed,
        public int $pending,
        public string $sampleTransactionId,
    ) {
    }

    public function directionLabel(): string
    {
        return CashDirection::INFLOW === $this->direction ? 'Поступление' : 'Списание';
    }
}
