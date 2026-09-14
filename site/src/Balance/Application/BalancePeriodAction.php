<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Exception\BalanceLedgerException;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class BalancePeriodAction
{
    public function __construct(private readonly Connection $db, private readonly BalanceAccess $access, private readonly LedgerQuery $query)
    {
    }

    /** @return array{drafts:int} */
    public function __invoke(string $companyId, string $actorId, string $month, bool $close, string $reason): array
    {
        $this->access->require($companyId, $actorId, $close ? 'manage_periods' : 'reopen_periods');

        $date = LedgerQuery::date($month);
        if ($date->format('Y-m-01') !== $month || '' === trim($reason)) {
            throw new BalanceLedgerException('Укажите начало месяца и основание.');
        }

        return $this->db->transactional(function () use ($companyId, $actorId, $month, $close, $reason, $date): array {
            $this->access->lockForMutation($companyId, $actorId, $close ? 'manage_periods' : 'reopen_periods');
            $book = $this->db->fetchAssociative('SELECT id,initialized,start_date FROM balance_books WHERE company_id=? FOR UPDATE', [$companyId]);
            $this->access->require($companyId, $actorId, $close ? 'manage_periods' : 'reopen_periods');
            if (!$book || !$book['initialized']) {
                throw new BalanceLedgerException('Сначала проведите начальные остатки.');
            }
            $last = $this->db->fetchOne('SELECT MAX(month) FROM balance_periods WHERE company_id=? AND is_closed=true', [$companyId]);
            if ($close) {
                $current = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-01');
                if ($month !== self::nextMonth($book['start_date'], $last ?: null) || $month >= $current) {
                    throw new BalanceLedgerException('Закрывать можно последовательно только завершенные месяцы.');
                }
                $this->assertIntegrity($companyId);
            } elseif ($last !== $month) {
                throw new BalanceLedgerException('Переоткрыть можно только последний закрытый месяц.');
            }
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $id = $this->db->fetchOne('SELECT id FROM balance_periods WHERE company_id=? AND month=?', [$companyId, $month]) ?: Uuid::uuid7()->toString();
            $this->db->executeStatement('INSERT INTO balance_periods (id,company_id,month,is_closed,changed_by,reason,changed_at) VALUES (?,?,?,?,?,?,?) ON CONFLICT (company_id,month) DO UPDATE SET is_closed=EXCLUDED.is_closed,changed_by=EXCLUDED.changed_by,reason=EXCLUDED.reason,changed_at=EXCLUDED.changed_at', [$id, $companyId, $month, $close, $actorId, trim($reason), $now], [\PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_BOOL, \PDO::PARAM_STR, \PDO::PARAM_STR, \PDO::PARAM_STR]);
            $this->db->executeStatement('UPDATE balance_books SET version=version+1,updated_at=? WHERE company_id=?', [$now, $companyId]);
            $this->db->insert('balance_audit_events', ['id' => Uuid::uuid7()->toString(), 'company_id' => $companyId, 'object_type' => 'period', 'object_id' => $id, 'action' => $close ? 'closed' : 'reopened', 'author_id' => $actorId, 'changes' => json_encode(['month' => $month, 'reason' => trim($reason)], \JSON_THROW_ON_ERROR), 'created_at' => $now]);
            $drafts = (int) $this->db->fetchOne("SELECT COUNT(*) FROM balance_operations WHERE company_id=? AND status='draft' AND operation_date>=? AND operation_date<?", [$companyId, $month, $date->modify('+1 month')->format('Y-m-d')]);

            return ['drafts' => $drafts];
        });
    }

    public static function nextMonth(string $startDate, ?string $lastClosed): string
    {
        return null === $lastClosed ? LedgerQuery::date($startDate)->format('Y-m-01') : LedgerQuery::date($lastClosed)->modify('+1 month')->format('Y-m-01');
    }

    private function assertIntegrity(string $companyId): void
    {
        $invalid = $this->db->fetchOne(<<<'SQL'
WITH movements AS (
 SELECT l.account_id,o.id,o.operation_date,o.posting_sequence,o.kind,SUM(CASE WHEN l.direction='increase' THEN l.amount::numeric ELSE -l.amount::numeric END) AS delta
 FROM balance_operations o JOIN balance_operation_lines l ON l.company_id=o.company_id AND l.operation_id=o.id
 WHERE o.company_id=:company AND o.status='posted' GROUP BY l.account_id,o.id
), running AS (
 SELECT account_id,SUM(delta) OVER(PARTITION BY account_id ORDER BY CASE WHEN kind='opening' THEN 0 ELSE 1 END,operation_date,posting_sequence ROWS UNBOUNDED PRECEDING) AS amount FROM movements
), totals AS (SELECT account_id,SUM(delta) AS amount FROM movements GROUP BY account_id)
SELECT EXISTS(SELECT 1 FROM running r JOIN balance_accounts a ON a.id=r.account_id AND a.company_id=:company WHERE NOT a.allow_negative AND r.amount<0)
 OR EXISTS(SELECT 1 FROM balance_accounts a LEFT JOIN totals t ON t.account_id=a.id LEFT JOIN balance_account_states s ON s.account_id=a.id AND s.company_id=a.company_id WHERE a.company_id=:company AND (s.id IS NULL OR s.balance<>COALESCE(t.amount,0)))
 OR EXISTS(SELECT 1 FROM balance_operations o JOIN balance_operation_lines l ON l.operation_id=o.id AND l.company_id=o.company_id JOIN balance_accounts a ON a.id=l.account_id AND a.company_id=l.company_id JOIN balance_articles c ON c.id=a.article_id AND c.company_id=a.company_id WHERE o.company_id=:company AND o.status='posted' GROUP BY o.id HAVING SUM((CASE WHEN c.type='asset' THEN 1 ELSE -1 END)*(CASE WHEN l.direction='increase' THEN l.amount::numeric ELSE -l.amount::numeric END))<>0)
SQL, ['company' => $companyId]);
        if ($invalid) {
            throw new BalanceLedgerException('Нарушена целостность учета: проверьте журнал, остатки и равенство сторон.');
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        if ('0' !== $this->query->balance($companyId, $today)['difference']) {
            throw new BalanceLedgerException('Актив и пассив не равны.');
        }
    }
}
