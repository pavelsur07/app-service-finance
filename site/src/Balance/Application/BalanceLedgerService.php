<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Security\BalanceAccess;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Intl\Currencies;

/** All financial mutations serialize on the company's book and commit atomically. */
final readonly class BalanceLedgerService
{
    public function __construct(private Connection $connection, private BalanceAccess $access)
    {
    }

    public function configureBook(string $companyId, string $currency, string $startDate, string $actorId): void
    {
        $this->access->require($companyId, $actorId, 'manage');
        LedgerAmount::date($startDate);
        if (!Currencies::exists($currency)) {
            throw new BalanceLedgerException('Неизвестная валюта учета.');
        }
        $this->connection->transactional(function () use ($companyId, $currency, $startDate, $actorId): void {
            $this->access->lockForMutation($companyId, $actorId, 'manage');
            $now = $this->now();
            $this->connection->executeStatement('INSERT INTO balance_books (id, company_id, created_at, updated_at) VALUES (:id, :company, :now, :now) ON CONFLICT (company_id) DO NOTHING', ['id' => Uuid::uuid7()->toString(), 'company' => $companyId, 'now' => $now]);
            $book = $this->lockBook($companyId, $actorId, 'manage');
            if ($book['initialized']) {
                throw new BalanceLedgerException('После начала учета валюта и начальная дата неизменяемы.', 409);
            }
            if ($book['currency'] !== $currency && $this->connection->fetchOne('SELECT 1 FROM balance_operations WHERE company_id = :company LIMIT 1', ['company' => $companyId])) {
                throw new BalanceLedgerException('Удалите черновики перед изменением валюты учета.', 409);
            }
            $this->connection->update('balance_books', ['currency' => $currency, 'start_date' => $startDate, 'updated_at' => $now], ['company_id' => $companyId]);
            $this->audit($companyId, $actorId, 'book', (string) $book['id'], 'configure', ['currency' => $currency, 'startDate' => $startDate]);
        });
    }

    /** @param list<array{accountId: string, direction: string, amount: string}> $lines */
    public function saveDraft(string $companyId, string $actorId, string $requestKey, string $kind, string $date, string $reason, array $lines, ?string $id = null, ?int $version = null): string
    {
        return $this->connection->transactional(function () use ($companyId, $actorId, $requestKey, $kind, $date, $reason, $lines, $id, $version): string {
            $book = $this->lockBook($companyId, $actorId, 'prepare');

            return $this->writeDraft($companyId, $actorId, $book, $requestKey, $kind, $date, $reason, $lines, $id, $version);
        });
    }

    public function post(string $companyId, string $actorId, string $id, int $expectedVersion, bool $confirmZeroOpening = false): void
    {
        $this->connection->transactional(function () use ($companyId, $actorId, $id, $expectedVersion, $confirmZeroOpening): void {
            $book = $this->lockBook($companyId, $actorId, 'post');
            $this->postLocked($companyId, $actorId, $id, $book, $expectedVersion, $confirmZeroOpening);
        });
    }

    public function reverse(string $companyId, string $actorId, string $id, string $date, string $reason, string $requestKey): string
    {
        return $this->connection->transactional(function () use ($companyId, $actorId, $id, $date, $reason, $requestKey): string {
            $book = $this->lockBook($companyId, $actorId, 'post');
            $original = $this->operation($companyId, $id);
            if ('posted' !== $original['status'] || 'opening' === $original['kind']) {
                throw new BalanceLedgerException('Сторно возможно только для проведенной операции, кроме начальных остатков.');
            }
            LedgerAmount::date($date);
            $existing = $this->connection->fetchAssociative('SELECT id, request_key, operation_date, reason FROM balance_operations WHERE company_id = :company AND original_operation_id = :id', ['company' => $companyId, 'id' => $id]);
            if (false !== $existing) {
                if ($existing['request_key'] !== $requestKey || $existing['operation_date'] !== $date || $existing['reason'] !== $reason) {
                    throw new BalanceLedgerException('Документ уже сторнирован другим запросом или с другими данными.', 409);
                }

                return (string) $existing['id'];
            }
            if ($date < $original['operation_date']) {
                throw new BalanceLedgerException('Дата сторно не может предшествовать исходной операции.');
            }
            $lines = [];
            foreach ($this->lines($companyId, $id) as $line) {
                $lines[] = ['accountId' => (string) $line['account_id'], 'direction' => 'increase' === $line['direction'] ? 'decrease' : 'increase', 'amount' => Money::fromMinor((int) $line['amount'], (string) $book['currency'])->toDecimalString()];
            }
            $reversalId = $this->writeDraft($companyId, $actorId, $book, $requestKey, 'reversal', $date, $reason, $lines, originalId: $id);
            $this->postLocked($companyId, $actorId, $reversalId, $book, 1);

            return $reversalId;
        });
    }

    public function deleteDraft(string $companyId, string $actorId, string $id, int $version): void
    {
        $this->connection->transactional(function () use ($companyId, $actorId, $id, $version): void {
            $this->lockBook($companyId, $actorId, 'prepare');
            $document = $this->operation($companyId, $id);
            $this->assertDraftVersion($document, $version);
            $this->audit($companyId, $actorId, 'operation', $id, 'delete_draft', $document);
            $this->audit($companyId, $actorId, 'request', Uuid::uuid5($companyId, (string) $document['request_key'])->toString(), 'draft_deleted', ['documentId' => $id, 'requestKey' => $document['request_key'], 'requestHash' => $document['request_hash']]);
            $this->connection->delete('balance_operation_lines', ['company_id' => $companyId, 'operation_id' => $id]);
            $this->connection->delete('balance_operations', ['company_id' => $companyId, 'id' => $id]);
        });
    }

    /** Explicit owner recovery; normal posting never silently rebuilds the journal. */
    public function rebuildCurrentStates(string $companyId, string $actorId, string $reason): int
    {
        if ('' === trim($reason)) {
            throw new BalanceLedgerException('Укажите основание восстановления остатков.');
        }

        return $this->connection->transactional(function () use ($companyId, $actorId, $reason): int {
            $book = $this->lockBook($companyId, $actorId, 'manage');
            $this->assertRebuildHistory($companyId, (bool) $book['initialized']);
            $totals = $this->connection->fetchAllAssociative("SELECT a.id, COALESCE(SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END), 0)::text AS balance FROM balance_accounts a LEFT JOIN (balance_operation_lines l JOIN balance_operations o ON o.id = l.operation_id AND o.company_id = l.company_id AND o.status = 'posted') ON l.account_id = a.id AND l.company_id = a.company_id WHERE a.company_id = :company GROUP BY a.id", ['company' => $companyId]);
            $version = (int) $book['version'] + 1;
            foreach ($totals as $row) {
                $this->storeAccountState($companyId, (string) $row['id'], (string) $row['balance'], $version);
            }
            $mismatch = $this->connection->fetchOne("SELECT 1 FROM balance_accounts a LEFT JOIN balance_account_states s ON s.account_id = a.id AND s.company_id = a.company_id LEFT JOIN (SELECT l.account_id, SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END) AS balance FROM balance_operation_lines l JOIN balance_operations o ON o.id = l.operation_id AND o.company_id = l.company_id WHERE l.company_id = :company AND o.status = 'posted' GROUP BY l.account_id) t ON t.account_id = a.id WHERE a.company_id = :company AND (s.id IS NULL OR s.balance <> COALESCE(t.balance, 0)) LIMIT 1", ['company' => $companyId]);
            if (false !== $mismatch) {
                throw new BalanceLedgerException('Сверка восстановленных остатков не пройдена.', 409);
            }
            $this->connection->update('balance_books', ['version' => $version, 'updated_at' => $this->now()], ['company_id' => $companyId]);
            $this->audit($companyId, $actorId, 'book', (string) $book['id'], 'states_rebuilt', ['accounts' => count($totals), 'reason' => trim($reason)]);

            return count($totals);
        });
    }

    private function assertRebuildHistory(string $companyId, bool $initialized): void
    {
        $openings = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_operations WHERE company_id = :company AND kind = 'opening' AND status = 'posted'", ['company' => $companyId]);
        if (($initialized && 1 !== $openings) || (!$initialized && $this->connection->fetchOne("SELECT 1 FROM balance_operations WHERE company_id = :company AND status = 'posted' LIMIT 1", ['company' => $companyId]))) {
            throw new BalanceLedgerException('Состояние начала учета не соответствует журналу.', 409);
        }
        $invalid = $this->connection->fetchOne(<<<'SQL'
WITH RECURSIVE ancestry AS (
    SELECT a.id AS account_id, c.id AS article_id, c.parent_id
    FROM balance_accounts a JOIN balance_articles c ON c.id = a.article_id AND c.company_id = a.company_id
    WHERE a.company_id = :company
    UNION ALL
    SELECT h.account_id, c.id, c.parent_id FROM ancestry h
    JOIN balance_articles c ON c.id = h.parent_id AND c.company_id = :company
), memberships AS (
    SELECT account_id, 'article:' || article_id::text AS target, TRUE AS allow_negative FROM ancestry
    UNION ALL
    SELECT a.id, 'account:' || a.id::text, a.allow_negative FROM balance_accounts a WHERE a.company_id = :company
    UNION ALL
    SELECT a.id, 'side:' || c.type, TRUE FROM balance_accounts a
    JOIN balance_articles c ON c.id = a.article_id AND c.company_id = a.company_id WHERE a.company_id = :company
), changes AS (
    SELECT m.target, m.allow_negative, o.id, o.kind, o.operation_date, o.posting_sequence,
        SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END) AS delta
    FROM balance_operations o JOIN balance_operation_lines l ON l.operation_id = o.id AND l.company_id = o.company_id
    JOIN memberships m ON m.account_id = l.account_id WHERE o.company_id = :company AND o.status = 'posted'
    GROUP BY m.target, m.allow_negative, o.id
), running AS (
    SELECT allow_negative, SUM(delta) OVER(PARTITION BY target ORDER BY CASE WHEN kind = 'opening' THEN 0 ELSE 1 END, operation_date, posting_sequence ROWS UNBOUNDED PRECEDING) AS balance FROM changes
)
SELECT EXISTS(SELECT 1 FROM running WHERE balance > CAST(:maximum AS numeric) OR balance < CAST(:minimum AS numeric) OR (NOT allow_negative AND balance < 0))
OR EXISTS(
    SELECT o.id FROM balance_operations o
    JOIN balance_operation_lines l ON l.operation_id = o.id AND l.company_id = o.company_id
    JOIN balance_accounts a ON a.id = l.account_id AND a.company_id = l.company_id
    JOIN balance_articles c ON c.id = a.article_id AND c.company_id = a.company_id
    WHERE o.company_id = :company AND o.status = 'posted' GROUP BY o.id
    HAVING SUM((CASE WHEN c.type = 'asset' THEN 1 ELSE -1 END) * (CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END)) <> 0
)
SQL, ['company' => $companyId, 'maximum' => (string) \PHP_INT_MAX, 'minimum' => (string) \PHP_INT_MIN]);
        if ($invalid) {
            throw new BalanceLedgerException('Журнал не прошел проверку равенства сторон, диапазона или отрицательных остатков. Восстановление отменено.', 409);
        }
    }

    /** @param list<array{accountId: string, direction: string, amount: string}> $otherLines */
    public function prepareTargetCorrection(string $companyId, string $actorId, string $requestKey, string $accountId, string $date, string $target, string $reason, int $journalVersion, array $otherLines, ?string $id = null, ?int $version = null): string
    {
        return $this->connection->transactional(function () use ($companyId, $actorId, $requestKey, $accountId, $date, $target, $reason, $journalVersion, $otherLines, $id, $version): string {
            $book = $this->lockBook($companyId, $actorId, 'prepare');

            return $this->targetDraftLocked($companyId, $actorId, $book, $requestKey, $accountId, $date, $target, $reason, $journalVersion, $otherLines, $id, $version);
        });
    }

    /** @param list<array{accountId: string, direction: string, amount: string}> $otherLines */
    public function postTargetCorrection(string $companyId, string $actorId, string $requestKey, string $accountId, string $date, string $target, string $reason, int $journalVersion, array $otherLines): string
    {
        return $this->connection->transactional(function () use ($companyId, $actorId, $requestKey, $accountId, $date, $target, $reason, $journalVersion, $otherLines): string {
            $book = $this->lockBook($companyId, $actorId, 'post');
            $this->access->require($companyId, $actorId, 'prepare');
            $id = $this->targetDraftLocked($companyId, $actorId, $book, $requestKey, $accountId, $date, $target, $reason, $journalVersion, $otherLines);
            $this->postLocked($companyId, $actorId, $id, $book, 1);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $book
     * @param list<array{accountId: string, direction: string, amount: string}> $otherLines
     */
    private function targetDraftLocked(string $companyId, string $actorId, array $book, string $requestKey, string $accountId, string $date, string $target, string $reason, int $journalVersion, array $otherLines, ?string $id = null, ?int $version = null): string
    {
        $this->account($companyId, $accountId);
        LedgerAmount::date($date);
        $targetMinor = $this->signedMinor($target, (string) $book['currency']);
        usort($otherLines, static fn (array $a, array $b): int => strcmp($a['accountId'], $b['accountId']));
        $hash = hash('sha256', json_encode([$accountId, $date, $targetMinor, $reason, $journalVersion, $otherLines], \JSON_THROW_ON_ERROR));
        if (null === $id) {
            $existing = $this->connection->fetchAssociative('SELECT id, request_hash FROM balance_operations WHERE company_id = :company AND request_key = :key', ['company' => $companyId, 'key' => $requestKey]);
            if (false !== $existing) {
                if ($existing['request_hash'] !== $hash) {
                    throw new BalanceLedgerException('Ключ запроса уже использован с другими данными.', 409);
                }

                return (string) $existing['id'];
            }
        }
        if ((int) $book['version'] !== $journalVersion) {
            throw new BalanceLedgerException('Остатки изменились. Обновите расчет корректировки.', 409);
        }
        $current = $this->connection->fetchOne("SELECT COALESCE(SUM(CASE WHEN l.direction = 'increase' THEN l.amount ELSE -l.amount END), 0) FROM balance_operation_lines l JOIN balance_operations o ON o.id = l.operation_id AND o.company_id = l.company_id WHERE l.company_id = :company AND l.account_id = :account AND o.status = 'posted' AND o.operation_date <= :date", ['company' => $companyId, 'account' => $accountId, 'date' => $date]);
        $delta = bcsub($targetMinor, (string) $current, 0);
        LedgerAmount::assertRange(ltrim($delta, '-'));
        if ('0' === $delta) {
            throw new BalanceLedgerException('Указанный остаток уже установлен.');
        }
        $otherLines[] = ['accountId' => $accountId, 'direction' => str_starts_with($delta, '-') ? 'decrease' : 'increase', 'amount' => Money::fromMinor((int) ltrim($delta, '-'), (string) $book['currency'])->toDecimalString()];
        $id = $this->writeDraft($companyId, $actorId, $book, $requestKey, 'correction', $date, $reason, $otherLines, $id, $version, requestHash: $hash);
        $this->audit($companyId, $actorId, 'operation', $id, 'target_prepared', ['accountId' => $accountId, 'date' => $date, 'target' => $targetMinor, 'delta' => $delta, 'journalVersion' => $journalVersion]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function lockBook(string $companyId, string $actorId, string $permission): array
    {
        // Company membership/financial rights serialize before the book lock.
        $this->access->lockForMutation($companyId, $actorId, $permission);
        $book = $this->connection->fetchAssociative('SELECT id, currency, start_date, initialized, version, next_document_number, next_posting_sequence FROM balance_books WHERE company_id = :company FOR UPDATE', ['company' => $companyId]);
        if (false === $book) {
            throw new BalanceLedgerException('Сначала настройте учет баланса.');
        }
        $this->access->require($companyId, $actorId, $permission);

        return $book;
    }

    /**
     * @param array<string, mixed> $book
     * @param list<array{accountId: string, direction: string, amount: string}> $lines
     */
    private function writeDraft(string $companyId, string $actorId, array $book, string $requestKey, string $kind, string $date, string $reason, array $lines, ?string $id = null, ?int $version = null, ?string $originalId = null, ?string $requestHash = null): string
    {
        LedgerAmount::date($date);
        if (null === $book['currency'] || null === $book['start_date']) {
            throw new BalanceLedgerException('Сначала задайте валюту и дату начала учета.');
        }
        if (!in_array($kind, ['opening', 'operation', 'correction', 'reversal'], true) || ('reversal' === $kind && null === $originalId)) {
            throw new BalanceLedgerException('Недопустимый вид документа.');
        }
        if ('' === trim($requestKey) || strlen($requestKey) > 128) {
            throw new BalanceLedgerException('Укажите ключ запроса длиной до 128 символов.');
        }
        if (null === $id && $this->connection->fetchOne("SELECT 1 FROM balance_audit_events WHERE company_id = :company AND object_type = 'request' AND object_id = :request AND action = 'draft_deleted' LIMIT 1", ['company' => $companyId, 'request' => Uuid::uuid5($companyId, $requestKey)->toString()])) {
            throw new BalanceLedgerException('Документ этого запроса удален. Для нового документа используйте новый ключ запроса.', 409);
        }
        $normalized = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!Uuid::isValid($line['accountId']) || !in_array($line['direction'], ['increase', 'decrease'], true) || isset($seen[$line['accountId']])) {
                throw new BalanceLedgerException('Укажите различные счета и корректное направление изменения.');
            }
            $this->account($companyId, $line['accountId']);
            $seen[$line['accountId']] = true;
            $normalized[] = ['accountId' => $line['accountId'], 'direction' => $line['direction'], 'amount' => LedgerAmount::minor($line['amount'], (string) $book['currency'])];
        }
        usort($normalized, static fn (array $a, array $b): int => strcmp($a['accountId'], $b['accountId']));
        $hash = $requestHash ?? hash('sha256', json_encode([$kind, $date, $reason, $normalized, $originalId], \JSON_THROW_ON_ERROR));
        if (null === $id) {
            $existing = $this->connection->fetchAssociative('SELECT id, request_hash FROM balance_operations WHERE company_id = :company AND request_key = :key', ['company' => $companyId, 'key' => $requestKey]);
            if (false !== $existing) {
                if ($existing['request_hash'] !== $hash) {
                    throw new BalanceLedgerException('Ключ запроса уже использован с другими данными.', 409);
                }

                return (string) $existing['id'];
            }
            if ('opening' === $kind && $this->connection->fetchOne("SELECT 1 FROM balance_operations WHERE company_id = :company AND kind = 'opening'", ['company' => $companyId])) {
                throw new BalanceLedgerException('Документ начальных остатков уже существует.', 409);
            }
            $id = Uuid::uuid7()->toString();
            $this->connection->insert('balance_operations', ['id' => $id, 'company_id' => $companyId, 'number' => $book['next_document_number'], 'kind' => $kind, 'operation_date' => $date, 'status' => 'draft', 'reason' => $reason, 'author_id' => $actorId, 'original_operation_id' => $originalId, 'request_key' => $requestKey, 'request_hash' => $hash, 'version' => 1, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
            $this->connection->executeStatement('UPDATE balance_books SET next_document_number = next_document_number + 1 WHERE company_id = :company', ['company' => $companyId]);
            $action = 'create_draft';
        } else {
            $old = $this->operation($companyId, $id);
            $this->assertDraftVersion($old, $version);
            if ($old['kind'] !== $kind) {
                throw new BalanceLedgerException('Вид документа нельзя изменять.');
            }
            $this->connection->update('balance_operations', ['operation_date' => $date, 'reason' => $reason, 'version' => (int) $old['version'] + 1, 'updated_at' => $this->now()], ['company_id' => $companyId, 'id' => $id]);
            $this->connection->delete('balance_operation_lines', ['company_id' => $companyId, 'operation_id' => $id]);
            $action = 'update_draft';
        }
        foreach ($normalized as $line) {
            $this->connection->insert('balance_operation_lines', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'operation_id' => $id, 'account_id' => $line['accountId'], 'direction' => $line['direction'], 'amount' => $line['amount']]);
        }
        $this->rememberDocumentReferences($companyId, $actorId, $id, array_keys($seen));
        $this->audit($companyId, $actorId, 'operation', $id, $action, ['date' => $date, 'reason' => $reason, 'lines' => $normalized]);

        return $id;
    }

    /** @param list<string> $accountIds */
    private function rememberDocumentReferences(string $companyId, string $actorId, string $documentId, array $accountIds): void
    {
        if ([] === $accountIds) {
            return;
        }
        // One durable marker per object; draft removal must not erase past use.
        // Company/book locking serializes this check-and-insert with deletion.
        $objects = $this->connection->fetchAllAssociative(<<<'SQL'
WITH RECURSIVE articles AS (
    SELECT c.id, c.parent_id FROM balance_articles c
    JOIN balance_accounts a ON a.article_id = c.id AND a.company_id = c.company_id
    WHERE a.company_id = :company AND a.id IN (:accounts)
    UNION
    SELECT c.id, c.parent_id FROM balance_articles c JOIN articles p ON c.id = p.parent_id
    WHERE c.company_id = :company
), objects AS (
    SELECT 'article' AS object_type, id AS object_id FROM articles
    UNION ALL
    SELECT 'account', id FROM balance_accounts WHERE company_id = :company AND id IN (:accounts)
)
SELECT o.object_type, o.object_id FROM objects o
WHERE NOT EXISTS (
    SELECT 1 FROM balance_audit_events e WHERE e.company_id = :company
    AND e.object_type = o.object_type AND e.object_id = o.object_id AND e.action = 'document_referenced'
)
SQL, ['company' => $companyId, 'accounts' => $accountIds], ['accounts' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        foreach ($objects as $object) {
            $this->audit($companyId, $actorId, (string) $object['object_type'], (string) $object['object_id'], 'document_referenced', ['documentId' => $documentId]);
        }
    }

    /** @param array<string, mixed> $book */
    private function postLocked(string $companyId, string $actorId, string $id, array $book, int $expectedVersion, bool $confirmZeroOpening = false): void
    {
        $operation = $this->operation($companyId, $id);
        if ('posted' === $operation['status']) {
            return;
        }
        $this->assertDraftVersion($operation, $expectedVersion);
        $date = (string) $operation['operation_date'];
        if ($date > (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d') || $date < $book['start_date']) {
            throw new BalanceLedgerException('Дата проведения должна быть между началом учета и сегодняшним днем.');
        }
        if ($this->connection->fetchOne('SELECT 1 FROM balance_periods WHERE company_id = :company AND month = :month AND is_closed = TRUE', ['company' => $companyId, 'month' => substr($date, 0, 7).'-01'])) {
            throw new BalanceLedgerException('Период закрыт. Проведите корректировку в открытом периоде.', 409);
        }
        if ('' === trim((string) $operation['reason'])) {
            throw new BalanceLedgerException('Укажите основание операции.');
        }
        if ('opening' === $operation['kind']) {
            if ($book['initialized'] || $date !== $book['start_date']) {
                throw new BalanceLedgerException('Начальные остатки проводятся один раз на дату начала учета.');
            }
        } elseif (!$book['initialized']) {
            throw new BalanceLedgerException('Сначала проведите начальные остатки.');
        }
        $lines = $this->lines($companyId, $id);
        if ('opening' === $operation['kind'] && [] === $lines && !$confirmZeroOpening) {
            throw new BalanceLedgerException('Подтвердите начало учета с нулевыми остатками.');
        }
        if ('opening' !== $operation['kind'] && count($lines) < 2) {
            throw new BalanceLedgerException('Для операции нужны минимум два различных счета.');
        }
        $assets = '0';
        $passive = '0';
        foreach ($lines as $line) {
            $account = $this->account($companyId, (string) $line['account_id']);
            if ($account['is_archived'] || $account['article_archived']) {
                throw new BalanceLedgerException('Верните архивный счет и статью в активное состояние.');
            }
            $delta = 'increase' === $line['direction'] ? (string) $line['amount'] : '-'.$line['amount'];
            if ('asset' === $account['type']) {
                $assets = bcadd($assets, $delta, 0);
            } else {
                $passive = bcadd($passive, $delta, 0);
            }
        }
        if (0 !== bccomp($assets, $passive, 0)) {
            throw new BalanceLedgerException('Изменения актива и пассива должны быть равны.');
        }
        $targetJson = $this->connection->fetchOne("SELECT changes FROM balance_audit_events WHERE company_id = :company AND object_id = :id AND action = 'target_prepared' ORDER BY created_at DESC, id DESC LIMIT 1", ['company' => $companyId, 'id' => $id]);
        if (false !== $targetJson) {
            $target = json_decode((string) $targetJson, true, flags: \JSON_THROW_ON_ERROR);
            if ((int) $target['journalVersion'] !== (int) $book['version']) {
                throw new BalanceLedgerException('Остатки изменились. Обновите расчет корректировки.', 409);
            }
            $targetLine = array_values(array_filter($lines, static fn (array $line): bool => $line['account_id'] === $target['accountId']));
            $actualDelta = [] !== $targetLine ? ('increase' === $targetLine[0]['direction'] ? '' : '-').$targetLine[0]['amount'] : null;
            if ($date !== $target['date'] || $actualDelta !== $target['delta']) {
                throw new BalanceLedgerException('Дата или сумма целевого счета изменена. Обновите расчет корректировки.', 409);
            }
        }
        $sequence = (string) $book['next_posting_sequence'];
        $version = (int) $book['version'] + 1;
        $this->connection->update('balance_operations', ['status' => 'posted', 'posted_by' => $actorId, 'posted_at' => $this->now(), 'posting_sequence' => $sequence, 'updated_at' => $this->now(), 'version' => (int) $operation['version'] + 1], ['company_id' => $companyId, 'id' => $id]);
        $this->assertAggregateRanges($companyId, $id, $date);
        foreach ($lines as $line) {
            $delta = 'increase' === $line['direction'] ? (string) $line['amount'] : '-'.$line['amount'];
            $this->updateAccountState($companyId, (string) $line['account_id'], $delta, $date, $version, $sequence);
        }
        $this->connection->executeStatement('UPDATE balance_books SET initialized = TRUE, next_posting_sequence = next_posting_sequence + 1, version = :version, updated_at = :now WHERE company_id = :company', ['company' => $companyId, 'version' => $version, 'now' => $this->now()]);
        $this->audit($companyId, $actorId, 'operation', $id, 'post', ['sequence' => $sequence]);
    }

    /** Validate only changed aggregates and the suffix after the insertion date. */
    private function assertAggregateRanges(string $companyId, string $operationId, string $date): void
    {
        $overflow = $this->connection->fetchOne(<<<'SQL'
WITH RECURSIVE ancestry AS (
    SELECT a.id AS account_id, c.id AS article_id, c.parent_id, c.type
    FROM balance_accounts a JOIN balance_articles c ON c.id = a.article_id AND c.company_id = a.company_id
    WHERE a.company_id = :company
    UNION ALL
    SELECT h.account_id, c.id, c.parent_id, c.type
    FROM ancestry h JOIN balance_articles c ON c.id = h.parent_id AND c.company_id = :company
), memberships AS MATERIALIZED (
    SELECT account_id, 'article:' || article_id::text AS target FROM ancestry
    UNION
    SELECT account_id, 'side:' || type AS target FROM ancestry
), targets AS MATERIALIZED (
    SELECT m.target, SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END) AS delta
    FROM balance_operation_lines l JOIN memberships m ON m.account_id = l.account_id
    WHERE l.company_id = :company AND l.operation_id = :operation
    GROUP BY m.target
    HAVING SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END) <> 0
), current_totals AS (
    SELECT t.target, COALESCE(SUM(s.balance::numeric), 0) + t.delta AS balance
    FROM targets t JOIN memberships m ON m.target = t.target
    LEFT JOIN balance_account_states s ON s.account_id = m.account_id AND s.company_id = :company
    GROUP BY t.target, t.delta
), future_changes AS (
    SELECT m.target, o.id, o.operation_date, o.posting_sequence,
        SUM(CASE WHEN l.direction = 'increase' THEN l.amount::numeric ELSE -l.amount::numeric END) AS delta
    FROM balance_operations o
    -- OFFSET 0 keeps movement lookup parameterized by each date-bounded document.
    JOIN LATERAL (
        SELECT account_id, direction, amount FROM balance_operation_lines
        WHERE operation_id = o.id AND company_id = o.company_id OFFSET 0
    ) l ON TRUE
    JOIN memberships m ON m.account_id = l.account_id
    JOIN targets t ON t.target = m.target
    WHERE o.company_id = :company AND o.status = 'posted' AND o.operation_date > :date
    GROUP BY m.target, o.id
), checkpoints AS (
    SELECT balance FROM current_totals
    UNION ALL
    SELECT c.balance - SUM(f.delta) OVER (
        PARTITION BY f.target ORDER BY f.operation_date DESC, f.posting_sequence DESC
        ROWS UNBOUNDED PRECEDING
    ) AS balance
    FROM future_changes f JOIN current_totals c ON c.target = f.target
)
SELECT 1 FROM checkpoints WHERE balance > CAST(:maximum AS numeric) OR balance < CAST(:minimum AS numeric) LIMIT 1
SQL, ['company' => $companyId, 'operation' => $operationId, 'date' => $date, 'maximum' => (string) \PHP_INT_MAX, 'minimum' => (string) \PHP_INT_MIN]);
        if (false !== $overflow) {
            throw new BalanceLedgerException('Остаток статьи или стороны баланса выходит за допустимый диапазон на своей или последующей дате.');
        }
    }

    private function updateAccountState(string $companyId, string $accountId, string $delta, string $date, int $version, string $sequence): void
    {
        $account = $this->account($companyId, $accountId);
        $current = $this->connection->fetchOne('SELECT balance FROM balance_account_states WHERE company_id = :company AND account_id = :account', ['company' => $companyId, 'account' => $accountId]);
        if (false === $current && $this->connection->fetchOne("SELECT 1 FROM balance_operation_lines l JOIN balance_operations o ON o.id = l.operation_id AND o.company_id = l.company_id WHERE l.company_id = :company AND l.account_id = :account AND o.status = 'posted' AND o.posting_sequence < :sequence LIMIT 1", ['company' => $companyId, 'account' => $accountId, 'sequence' => $sequence])) {
            throw new BalanceLedgerException('Отсутствует сохраненный остаток использованного счета. Владелец должен восстановить остатки из журнала.', 409);
        }
        $balance = bcadd(false === $current ? '0' : (string) $current, $delta, 0);
        $this->assertAccountBalance($balance, $account);
        // New documents have the latest posting sequence, so existing documents
        // on the same date precede this insertion. Walk only strictly later dates
        // backwards from the updated current state to the insertion boundary.
        $rows = $this->connection->fetchAllAssociative("SELECT l.direction, l.amount FROM balance_operations o JOIN LATERAL (SELECT direction, amount FROM balance_operation_lines WHERE operation_id = o.id AND company_id = o.company_id AND account_id = :account OFFSET 0) l ON TRUE WHERE o.company_id = :company AND o.status = 'posted' AND o.operation_date > :date ORDER BY o.operation_date DESC, o.posting_sequence DESC", ['company' => $companyId, 'account' => $accountId, 'date' => $date]);
        $historical = $balance;
        foreach ($rows as $row) {
            $historical = 'increase' === $row['direction'] ? bcsub($historical, (string) $row['amount'], 0) : bcadd($historical, (string) $row['amount'], 0);
            $this->assertAccountBalance($historical, $account);
        }
        $this->storeAccountState($companyId, $accountId, $balance, $version);
    }

    private function storeAccountState(string $companyId, string $accountId, string $balance, int $version): void
    {
        LedgerAmount::assertRange($balance);
        $this->connection->executeStatement('INSERT INTO balance_account_states (id, company_id, account_id, balance, journal_version, updated_at) VALUES (:id, :company, :account, :balance, :version, :now) ON CONFLICT (company_id, account_id) DO UPDATE SET balance = EXCLUDED.balance, journal_version = EXCLUDED.journal_version, updated_at = EXCLUDED.updated_at', ['id' => Uuid::uuid7()->toString(), 'company' => $companyId, 'account' => $accountId, 'balance' => $balance, 'version' => $version, 'now' => $this->now()]);
    }

    /** @param array<string, mixed> $account */
    private function assertAccountBalance(string $balance, array $account): void
    {
        LedgerAmount::assertRange($balance);
        if (!$account['allow_negative'] && bccomp($balance, '0', 0) < 0) {
            throw new BalanceLedgerException('Операция создает отрицательный остаток счета «'.$account['name'].'» на своей или последующей дате.');
        }
    }

    /** @return array<string, mixed> */
    private function account(string $companyId, string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new BalanceLedgerException('Счет не найден.', 404);
        }
        $account = $this->connection->fetchAssociative('SELECT a.id, a.name, a.allow_negative, a.is_archived, c.type, c.is_archived AS article_archived FROM balance_accounts a JOIN balance_articles c ON c.id = a.article_id AND c.company_id = a.company_id WHERE a.company_id = :company AND a.id = :id', ['company' => $companyId, 'id' => $id]);
        if (false === $account) {
            throw new BalanceLedgerException('Счет не найден в активной компании.', 404);
        }

        $account['article_archived'] = (bool) $this->connection->fetchOne('WITH RECURSIVE ancestors AS (SELECT c.id, c.parent_id, c.is_archived FROM balance_articles c JOIN balance_accounts a ON a.article_id = c.id AND a.company_id = c.company_id WHERE a.company_id = :company AND a.id = :id UNION ALL SELECT p.id, p.parent_id, p.is_archived FROM balance_articles p JOIN ancestors c ON c.parent_id = p.id WHERE p.company_id = :company) SELECT COALESCE(BOOL_OR(is_archived), FALSE) FROM ancestors', ['company' => $companyId, 'id' => $id]);

        return $account;
    }

    /** @return array<string, mixed> */
    private function operation(string $companyId, string $id): array
    {
        if (!Uuid::isValid($id)) {
            throw new BalanceLedgerException('Документ не найден.', 404);
        }
        $operation = $this->connection->fetchAssociative('SELECT id, kind, operation_date, status, reason, version, original_operation_id, request_key, request_hash FROM balance_operations WHERE company_id = :company AND id = :id', ['company' => $companyId, 'id' => $id]);
        if (false === $operation) {
            throw new BalanceLedgerException('Документ не найден в активной компании.', 404);
        }

        return $operation;
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $companyId, string $id): array
    {
        return $this->connection->fetchAllAssociative('SELECT account_id, direction, amount FROM balance_operation_lines WHERE company_id = :company AND operation_id = :id ORDER BY account_id', ['company' => $companyId, 'id' => $id]);
    }

    /** @param array<string, mixed> $document */
    private function assertDraftVersion(array $document, ?int $version): void
    {
        if ('draft' !== $document['status'] || (int) $document['version'] !== $version) {
            throw new BalanceLedgerException('Документ уже проведен или изменен. Обновите страницу.', 409);
        }
    }

    /** @param array<string, mixed> $changes */
    private function audit(string $companyId, string $actorId, string $type, string $id, string $action, array $changes): void
    {
        $this->connection->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => $type, 'object_id' => $id, 'action' => $action, 'author_id' => $actorId, 'changes' => json_encode($changes, \JSON_THROW_ON_ERROR), 'created_at' => $this->now()]);
    }

    private function signedMinor(string $value, string $currency): string
    {
        $negative = str_starts_with($value, '-');
        $minor = LedgerAmount::minor($negative ? substr($value, 1) : $value, $currency, true);

        return $negative && '0' !== $minor ? '-'.$minor : $minor;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s.u');
    }
}
