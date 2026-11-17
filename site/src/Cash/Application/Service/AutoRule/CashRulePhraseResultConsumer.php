<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Application\AutoRule\RequestCashRulePhraseAction;
use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use Doctrine\DBAL\Connection;

final readonly class CashRulePhraseResultConsumer implements AiTaskResultConsumerInterface
{
    public function __construct(private Connection $db, private CashRulePhraseText $text, private CashTransactionAutoRuleCandidateQuery $history)
    {
    }

    public function supports(string $taskType): bool
    {
        return RequestCashRulePhraseAction::TASK === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        $search = $this->db->fetchAssociative("SELECT counterparty_id, category_id, direction FROM cash_rule_phrase_search WHERE company_id = ? AND task_id = ? AND status IN ('sending', 'pending', 'retryable')", [$result->companyId, $result->taskId]);
        if (false === $search) {
            return;
        }
        $phrase = null;
        $status = 'failed';
        if (\in_array($result->status, [AiResultStatus::NEEDS_REVIEW, AiResultStatus::ACCEPTED], true)
            && 'cash_rule_phrase/v1' === $result->promptVersion && null !== $result->output && ['phrase'] === array_keys($result->output)) {
            $encoded = $result->output['phrase'];
            if (null === $encoded) {
                $status = 'insufficient';
            } elseif (\is_string($encoded)) {
                $phrase = $this->text->decode(
                    $encoded,
                    $result->companyId.'/'.$search['counterparty_id'].'/'.$search['category_id'],
                    $this->history->phraseHistory($result->companyId, $search['counterparty_id'], $search['direction']),
                );
                $status = null === $phrase ? 'failed' : 'ready';
            }
        }
        $this->db->executeStatement("UPDATE cash_rule_phrase_search SET status = ?, phrase = ? WHERE company_id = ? AND task_id = ? AND status IN ('sending', 'pending', 'retryable')", [$status, $phrase, $result->companyId, $result->taskId]);
    }
}
