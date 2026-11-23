<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\DTO\CashTransactionAutoRuleMatchResult;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use Doctrine\DBAL\Connection;

/** Оба применения вызывают этот метод под блокировкой операции, внутри своей транзакции. */
final readonly class CashAutoRuleConflictRecorder
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(CashTransaction $transaction, CashTransactionAutoRuleMatchResult $match): void
    {
        $rules = $match->conflicts['cashflowCategory'] ?? [];
        $revisions = array_map(static fn (CashTransactionAutoRule $rule): array => [
            'id' => $rule->getId(),
            'revision' => $rule->getRevision(),
        ], $rules);
        $this->connection->executeStatement(
            <<<'SQL'
                WITH start_observation AS (
                    INSERT INTO cash_auto_rule_observation (company_id, started_at)
                    VALUES (:company, CURRENT_TIMESTAMP)
                    ON CONFLICT (company_id) DO NOTHING
                )
                INSERT INTO cash_auto_rule_category_conflict (company_id, rule_id, revision, transaction_id, observed_at)
                SELECT :company, r.id, r.revision, :tx, CURRENT_TIMESTAMP
                FROM jsonb_to_recordset(CAST(:rules AS jsonb)) AS r(id uuid, revision int)
                ON CONFLICT (company_id, rule_id, revision, transaction_id) DO NOTHING
                SQL,
            [
                'company' => $transaction->getCompany()->getId(),
                'tx' => $transaction->getId(),
                'rules' => json_encode($revisions, \JSON_THROW_ON_ERROR),
            ],
        );
    }
}
