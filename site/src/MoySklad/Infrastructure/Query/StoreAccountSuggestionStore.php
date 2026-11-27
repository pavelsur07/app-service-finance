<?php

declare(strict_types=1);

namespace App\MoySklad\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * ИИ-предложения счёта складу (`moysklad_store_account_suggestions`): строка пишется до постановки задачи —
 * ответ находит её по ai_task_id. Все запросы ограничены компанией. Одиночные statements, вызывать вне транзакции.
 */
final readonly class StoreAccountSuggestionStore
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string, array{fingerprint: string, promptVersion: string, status: string, attempts: int, seq: int, payloadHash: string, requestedAt: string}> по external id склада */
    public function requests(string $companyId, string $connectionId): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative('SELECT store_external_id, store_fingerprint, prompt_version, status, attempts, request_seq, payload_hash, requested_at FROM moysklad_store_account_suggestions WHERE company_id = ? AND connection_id = ?', [$companyId, $connectionId]) as $row) {
            $rows[(string) $row['store_external_id']] = ['fingerprint' => (string) $row['store_fingerprint'], 'promptVersion' => (string) $row['prompt_version'], 'status' => (string) $row['status'], 'attempts' => (int) $row['attempts'], 'seq' => (int) $row['request_seq'], 'payloadHash' => (string) $row['payload_hash'], 'requestedAt' => (string) $row['requested_at']];
        }

        return $rows;
    }

    /** @return array<string, array{fingerprint: string, accountCode: ?string, excluded: bool, confident: bool, reason: string}> только полученные ответы */
    public function answers(string $companyId, string $connectionId): array
    {
        $rows = [];
        foreach ($this->db->fetchAllAssociative("SELECT store_external_id, store_fingerprint, account_code, excluded, confident, reason FROM moysklad_store_account_suggestions WHERE company_id = ? AND connection_id = ? AND status = 'completed'", [$companyId, $connectionId]) as $row) {
            $rows[(string) $row['store_external_id']] = ['fingerprint' => (string) $row['store_fingerprint'], 'accountCode' => null === $row['account_code'] ? null : (string) $row['account_code'],
                'excluded' => (bool) $row['excluded'], 'confident' => (bool) $row['confident'], 'reason' => (string) $row['reason']];
        }

        return $rows;
    }

    /**
     * Ответ на тот же склад и промпт, пришедший после чтения строки прогоном, не возвращается в `requested`:
     * результат уже подтверждён сервису и повторно не придёт.
     */
    public function markRequested(string $companyId, string $connectionId, string $storeExternalId, string $fingerprint, string $promptVersion, string $taskId, int $attempt, int $seq, string $payloadHash, \DateTimeImmutable $now): void
    {
        $this->db->executeStatement("INSERT INTO moysklad_store_account_suggestions AS t (company_id, connection_id, store_external_id, ai_task_id, store_fingerprint, prompt_version, status, attempts, request_seq, payload_hash, requested_at)
            VALUES (?, ?, ?, ?, ?, ?, 'requested', ?, ?, ?, ?) ON CONFLICT (company_id, connection_id, store_external_id) DO UPDATE SET ai_task_id = EXCLUDED.ai_task_id,
            store_fingerprint = EXCLUDED.store_fingerprint, prompt_version = EXCLUDED.prompt_version, status = 'requested', attempts = EXCLUDED.attempts, request_seq = EXCLUDED.request_seq, payload_hash = EXCLUDED.payload_hash,
            account_code = NULL, excluded = false, confident = false, reason = NULL, requested_at = EXCLUDED.requested_at, completed_at = NULL
            WHERE t.status <> 'completed' OR t.store_fingerprint <> EXCLUDED.store_fingerprint OR t.prompt_version <> EXCLUDED.prompt_version",
            [$companyId, $connectionId, $storeExternalId, $taskId, $fingerprint, $promptVersion, $attempt, $seq, $payloadHash, self::utc($now)]);
    }

    /** Технический сбой ИИ: склад спросят снова следующим прогоном с новым ключом (лимит попыток — у вызывающего). */
    public function fail(string $companyId, string $taskId, \DateTimeImmutable $completedAt): void
    {
        $this->db->executeStatement("UPDATE moysklad_store_account_suggestions SET status = 'failed', completed_at = ? WHERE company_id = ? AND ai_task_id = ?", [self::utc($completedAt), $companyId, $taskId]);
    }

    public function forget(string $companyId, string $taskId): void
    {
        $this->db->executeStatement('DELETE FROM moysklad_store_account_suggestions WHERE company_id = ? AND ai_task_id = ?', [$companyId, $taskId]);
    }

    /** Ответ на свою задачу; задача, которую уже заменили (склад переименован), строку не найдёт. */
    public function complete(string $companyId, string $taskId, ?string $accountCode, bool $excluded, bool $confident, ?string $reason, \DateTimeImmutable $completedAt): void
    {
        $this->db->executeStatement("UPDATE moysklad_store_account_suggestions SET status = 'completed', account_code = ?, excluded = ?, confident = ?, reason = ?, completed_at = ?
            WHERE company_id = ? AND ai_task_id = ?", [$accountCode, $excluded ? 'true' : 'false', $confident ? 'true' : 'false', $reason, self::utc($completedAt), $companyId, $taskId]);
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
