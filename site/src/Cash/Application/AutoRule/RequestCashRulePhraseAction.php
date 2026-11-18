<?php

declare(strict_types=1);

namespace App\Cash\Application\AutoRule;

use App\Cash\Application\Service\AutoRule\CashRulePhraseText;
use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class RequestCashRulePhraseAction
{
    public const TASK = 'cash_rule_phrase';

    public function __construct(
        private AiTaskFacadeInterface $gateway,
        private CashTransactionAutoRuleCandidateQuery $history,
        private Connection $db,
        private CashRulePhraseText $text,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $userId, string $counterparty, string $direction, string $category): string
    {
        $budget = $this->gateway->isEnabledForCompany($companyId) ? $this->gateway->budgetFor($companyId) : null;
        if (null === $budget || $budget->exceeded()) {
            throw new \DomainException('Поиск фраз недоступен по настройкам или бюджету компании.');
        }
        $scope = $companyId.'/'.$counterparty.'/'.$category;
        $examples = [];
        $targetCount = 0;
        foreach ($this->history->phraseHistory($companyId, $counterparty, $direction, $budget->lookbackDays, $category) as $row) {
            $target = $category === $row->categoryId;
            $encoded = $this->text->encode($row->description, $scope);
            if ('' !== $encoded) {
                $examples[] = ['text' => $encoded, 'target' => $target];
                $targetCount += (int) $target;
            }
        }
        if ($targetCount < 2) {
            throw new \DomainException('Недостаточно подтверждённых операций с назначением.');
        }
        $payload = ['examples' => $examples];
        $key = hash('sha256', json_encode(['v1', $scope, $direction, $payload], \JSON_THROW_ON_ERROR));
        [$task, $attempt, $status, $claimedAt] = $this->db->transactional(function () use ($companyId, $counterparty, $direction, $category, $key): array {
            $first = $this->gateway->taskIdFor($companyId, self::TASK, $key.':1');
            $insertedAt = $this->db->fetchOne(<<<'SQL'
                INSERT INTO cash_rule_phrase_search (task_id, company_id, counterparty_id, direction, category_id, request_key, attempt, status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, 'sending', CURRENT_TIMESTAMP)
                ON CONFLICT (company_id, request_key) DO NOTHING RETURNING requested_at
                SQL, [$first, $companyId, $counterparty, $direction, $category, $key]);
            if (false !== $insertedAt) {
                return [$first, 1, 'sending', $insertedAt];
            }
            $row = $this->db->fetchAssociative(<<<'SQL'
                SELECT task_id, attempt, status,
                       CASE WHEN requested_at < CURRENT_TIMESTAMP - INTERVAL '1 hour' THEN 1 ELSE 0 END AS delayed
                FROM cash_rule_phrase_search WHERE company_id = ? AND request_key = ? FOR UPDATE
                SQL, [$companyId, $key]);
            if (false === $row) {
                throw new \LogicException('Не найдена созданная заявка на поиск фразы.');
            }
            $attempt = (int) $row['attempt'];
            $task = (string) $row['task_id'];
            $status = (string) $row['status'];
            if ('failed' === $status) {
                // Сервис окончательно закрыл старую задачу. Для явного повтора нужен новый ключ.
                ++$attempt;
                $task = $this->gateway->taskIdFor($companyId, self::TASK, $key.':'.$attempt);
            } elseif ('retryable' !== $status && !(\in_array($status, ['sending', 'pending'], true) && 1 === (int) $row['delayed'])) {
                return [$task, $attempt, $status, null];
            }
            $claimedAt = $this->db->fetchOne("UPDATE cash_rule_phrase_search SET task_id = ?, attempt = ?, status = 'sending', requested_at = CURRENT_TIMESTAMP WHERE company_id = ? AND request_key = ? RETURNING requested_at", [$task, $attempt, $companyId, $key]);

            return [$task, $attempt, 'sending', $claimedAt];
        });
        if (null === $claimedAt) {
            return $status;
        }
        // После commit. При обрыве до обновления строки новая отправка использует тот же ключ.
        try {
            $status = null === $this->gateway->requestTask($companyId, self::TASK, $payload, $key.':'.$attempt, $userId) ? 'retryable' : 'pending';
        } catch (\Exception $exception) {
            $this->logger->warning('Cash rule phrase dispatch failed.', ['companyId' => $companyId, 'taskId' => $task, 'exceptionClass' => $exception::class]);
            $status = 'retryable';
        }
        $this->db->executeStatement("UPDATE cash_rule_phrase_search SET status = ? WHERE company_id = ? AND task_id = ? AND status = 'sending' AND requested_at = ?", [$status, $companyId, $task, $claimedAt]);

        return (string) $this->db->fetchOne('SELECT status FROM cash_rule_phrase_search WHERE company_id = ? AND request_key = ?', [$companyId, $key]);
    }
}
