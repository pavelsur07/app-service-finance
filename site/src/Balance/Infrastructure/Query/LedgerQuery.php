<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Query;

use App\Balance\Domain\Policy\LedgerAmount;
use App\Balance\Exception\BalanceLedgerException;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class LedgerQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function book(string $companyId): ?array
    {
        return $this->db->fetchAssociative('SELECT id,company_id,currency,start_date,initialized,version,next_document_number,next_posting_sequence FROM balance_books WHERE company_id = ?', [$companyId]) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function accounts(string $companyId): array
    {
        return $this->db->fetchAllAssociative('SELECT a.id,a.company_id,a.article_id,a.code,a.name,a.allow_negative,a.is_archived, c.type, c.name AS article_name, COALESCE(s.balance, 0)::text AS balance,s.updated_at AS balance_updated_at,s.journal_version FROM balance_accounts a JOIN balance_articles c ON c.company_id=a.company_id AND c.id=a.article_id LEFT JOIN balance_account_states s ON s.company_id=a.company_id AND s.account_id=a.id WHERE a.company_id=? ORDER BY c.sort_order,c.name,a.name,a.id', [$companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function articles(string $companyId): array
    {
        return $this->db->fetchAllAssociative('SELECT id,name,code,type,kind,parent_id,level,sort_order,is_visible,is_archived FROM balance_articles WHERE company_id=? ORDER BY level,sort_order,name,id', [$companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function categories(string $companyId): array
    {
        $children = [];
        foreach ($this->articles($companyId) as $article) {
            $children[(string) ($article['parent_id'] ?? '')][] = $article;
        }
        $walk = function (string $parent, string $path) use (&$walk, $children): array {
            $rows = [];
            foreach ($children[$parent] ?? [] as $article) {
                $article['path_name'] = '' === $path ? (string) $article['name'] : $path.' / '.$article['name'];
                $rows[] = $article;
                array_push($rows, ...$walk((string) $article['id'], $article['path_name']));
            }

            return $rows;
        };

        return $walk('', '');
    }

    /** @return array<string,mixed> */
    public function category(string $companyId, string $id): array
    {
        $this->assertId($id);

        return $this->db->fetchAssociative('SELECT id,name,code,type,kind,parent_id,level,sort_order,is_visible,is_archived FROM balance_articles WHERE company_id=? AND id=?', [$companyId, $id]) ?: throw new BalanceLedgerException('Статья не найдена.', 404);
    }

    /** @return array<string,mixed> */
    public function account(string $companyId, string $id): array
    {
        $this->assertId($id);

        return $this->db->fetchAssociative('SELECT a.id,a.article_id,a.code,a.name,a.allow_negative,a.is_archived,c.type,c.name AS article_name,COALESCE(s.balance,0)::text AS balance,s.updated_at AS balance_updated_at,s.journal_version FROM balance_accounts a JOIN balance_articles c ON c.company_id=a.company_id AND c.id=a.article_id LEFT JOIN balance_account_states s ON s.company_id=a.company_id AND s.account_id=a.id WHERE a.company_id=? AND a.id=?', [$companyId, $id]) ?: throw new BalanceLedgerException('Счет не найден.', 404);
    }

    /** @return array<string, mixed> */
    public function statement(string $companyId, string $from, string $to): array
    {
        self::date($from);
        self::date($to);
        if ($from > $to) {
            throw new BalanceLedgerException('Начало периода позже окончания.');
        }

        return $this->snapshot(function () use ($companyId, $from, $to): array {
            $book = $this->book($companyId);
            $started = null !== $book && (bool) $book['initialized'] && $book['start_date'] <= $to;
            $effectiveFrom = $started ? max($from, (string) $book['start_date']) : null;
            $rows = $this->db->fetchAllAssociative(<<<'SQL'
SELECT a.id,a.name,a.code,a.article_id,a.is_archived,c.type,c.name AS article_name,
 COALESCE(SUM(CASE WHEN o.operation_date < :from OR o.kind='opening' THEN CASE WHEN l.direction='increase' THEN l.amount::numeric ELSE -l.amount::numeric END ELSE 0 END),0)::text AS opening,
 COALESCE(SUM(CASE WHEN o.operation_date >= :from AND o.kind<>'opening' AND l.direction='increase' THEN l.amount::numeric ELSE 0 END),0)::text AS increase,
 COALESCE(SUM(CASE WHEN o.operation_date >= :from AND o.kind<>'opening' AND l.direction='decrease' THEN l.amount::numeric ELSE 0 END),0)::text AS decrease
FROM balance_accounts a JOIN balance_articles c ON c.company_id=a.company_id AND c.id=a.article_id
LEFT JOIN (balance_operation_lines l JOIN balance_operations o ON o.company_id=l.company_id AND o.id=l.operation_id AND o.status='posted' AND o.operation_date<=:to) ON l.company_id=a.company_id AND l.account_id=a.id
WHERE a.company_id=:company GROUP BY a.id,c.type,c.name ORDER BY c.name,a.name,a.id
SQL, ['company' => $companyId, 'from' => $effectiveFrom ?? $from, 'to' => $to]);
            $asset = '0';
            $passive = '0';
            foreach ($rows as &$row) {
                $row['closing'] = bcsub(bcadd((string) $row['opening'], (string) $row['increase'], 0), (string) $row['decrease'], 0);
                if ('asset' === $row['type']) {
                    $asset = bcadd($asset, $row['closing'], 0);
                } else {
                    $passive = bcadd($passive, $row['closing'], 0);
                }
            }
            unset($row);
            LedgerAmount::assertRange($asset);
            LedgerAmount::assertRange($passive);
            foreach ($rows as $row) {
                foreach (['opening', 'closing'] as $key) {
                    LedgerAmount::assertRange((string) $row[$key]);
                }
            }

            return ['from' => $from, 'to' => $to, 'effective_from' => $effectiveFrom, 'accounting_started' => $started, 'accounts' => $rows, 'asset' => $asset, 'passive' => $passive, 'difference' => bcsub($asset, $passive, 0)];
        });
    }

    /** @return array<string, mixed> */
    public function balance(string $companyId, string $date): array
    {
        return $this->snapshot(function () use ($companyId, $date): array {
            $book = $this->book($companyId);

            return $this->statement($companyId, $date, $date) + ['book' => $book, 'articles' => $this->articles($companyId), 'initialized' => null !== $book && (bool) $book['initialized'] && $book['start_date'] <= $date];
        });
    }

    /** @return array<string, mixed> */
    public function compare(string $companyId, string $date1, string $date2): array
    {
        return $this->snapshot(function () use ($companyId, $date1, $date2): array {
            $before = $this->balance($companyId, $date1);
            $after = $this->balance($companyId, $date2);
            $amounts = [];
            foreach ($before['accounts'] as $row) {
                $amounts[$row['id']] = $row['closing'];
            }
            foreach ($after['accounts'] as &$row) {
                $row['before'] = $amounts[$row['id']] ?? '0';
                $row['change'] = bcsub($row['closing'], $row['before'], 0);
            } unset($row);

            return ['before' => $before, 'after' => $after];
        });
    }

    /**
     * Newest accounting date first. Within a date: drafts by descending number,
     * posted documents by descending posting sequence, then the opening document.
     * Cards retain ascending posting order for their running balances.
     *
     * @param array<string,string> $filters
     *
     * @return array<string,mixed>
     */
    public function journal(string $companyId, array $filters = [], int $page = 1): array
    {
        foreach (['author_id', 'account_id'] as $key) {
            if (isset($filters[$key]) && '' !== $filters[$key]) {
                $this->assertId($filters[$key], 422);
            }
        }
        $where = 'o.company_id=:company';
        $params = ['company' => $companyId];
        foreach (['status', 'kind', 'author_id'] as $key) {
            if (!empty($filters[$key])) {
                $where .= ' AND o.'.$key.'=:'.$key;
                $params[$key] = $filters[$key];
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (!empty($filters[$key])) {
                self::date($filters[$key]);
                $where .= ' AND o.operation_date'.$operator.':'.$key;
                $params[$key] = $filters[$key];
            }
        }
        if (!empty($filters['account_id'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM balance_operation_lines l WHERE l.company_id=o.company_id AND l.operation_id=o.id AND l.account_id=:account)';
            $params['account'] = $filters['account_id'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (o.number::text=:search OR o.reason ILIKE :term)';
            $params['search'] = $filters['search'];
            $params['term'] = '%'.$filters['search'].'%';
        }
        $page = max(1, $page);

        return $this->snapshot(function () use ($where, $params, $page): array {
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM balance_operations o WHERE '.$where, $params);
            $this->assertPage($page, $total);
            $offset = ($page - 1) * 50;
            $items = $this->db->fetchAllAssociative('SELECT o.id,o.number,o.kind,o.operation_date,o.status,o.reason,o.author_id,o.posted_at,o.version,o.original_operation_id, EXISTS(SELECT 1 FROM balance_operations r WHERE r.company_id=o.company_id AND r.original_operation_id=o.id AND r.status=\'posted\') AS is_reversed FROM balance_operations o WHERE '.$where.' ORDER BY o.operation_date DESC,CASE WHEN o.status=\'draft\' THEN 0 WHEN o.kind=\'opening\' THEN 2 ELSE 1 END,o.posting_sequence DESC NULLS LAST,o.number DESC LIMIT 50 OFFSET '.$offset, $params);

            return $this->page($items, $total, $page);
        });
    }

    /** @return array<string,mixed> */
    public function accountCard(string $companyId, string $accountId, string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        $this->assertId($accountId);

        return $this->card($companyId, $accountId, $from, $to, false, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function articleCard(string $companyId, string $articleId, string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        $this->assertId($articleId);

        return $this->card($companyId, $articleId, $from, $to, true, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function document(string $companyId, string $id): array
    {
        $this->assertId($id);

        return $this->snapshot(function () use ($companyId, $id): array {
            $document = $this->db->fetchAssociative('SELECT id,number,kind,operation_date,status,reason,author_id,posted_by,posted_at,posting_sequence,original_operation_id,request_key,version,created_at,updated_at FROM balance_operations WHERE company_id=? AND id=?', [$companyId, $id]);
            if (false === $document) {
                throw new BalanceLedgerException('Документ не найден.', 404);
            }
            $document['lines'] = $this->db->fetchAllAssociative('SELECT l.id,l.account_id,l.direction,l.amount::text AS amount,a.name AS account_name,a.article_id,c.type FROM balance_operation_lines l JOIN balance_accounts a ON a.company_id=l.company_id AND a.id=l.account_id JOIN balance_articles c ON c.company_id=a.company_id AND c.id=a.article_id WHERE l.company_id=? AND l.operation_id=? ORDER BY l.id', [$companyId, $id]);
            $document['reversal_id'] = $this->db->fetchOne('SELECT id FROM balance_operations WHERE company_id=? AND original_operation_id=?', [$companyId, $id]) ?: null;
            $target = $this->db->fetchOne("SELECT changes FROM balance_audit_events WHERE company_id=? AND object_type='operation' AND object_id=? AND action='target_prepared' ORDER BY created_at DESC,id DESC LIMIT 1", [$companyId, $id]);
            $document['target_preparation'] = false === $target ? null : json_decode((string) $target, true, 512, \JSON_THROW_ON_ERROR);

            return $document;
        });
    }

    /** @return list<array<string,mixed>> */
    public function periods(string $companyId): array
    {
        return $this->db->fetchAllAssociative('SELECT id,month,is_closed,changed_by,reason,changed_at FROM balance_periods WHERE company_id=? ORDER BY month DESC', [$companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function grants(string $companyId): array
    {
        return $this->db->fetchAllAssociative('SELECT id,user_id,can_prepare,can_post,can_manage_periods,can_reopen_periods FROM balance_access_grants WHERE company_id=? ORDER BY user_id', [$companyId]);
    }

    /** @return array<string,mixed> */
    public function audit(string $companyId, string $type, string $id, int $page = 1): array
    {
        $this->assertId($id);
        $page = max(1, $page);

        return $this->snapshot(function () use ($companyId, $type, $id, $page): array {
            $params = [$companyId, $type, $id];
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND object_type=? AND object_id=?', $params);
            $this->assertPage($page, $total);
            $items = $this->db->fetchAllAssociative('SELECT id,object_type,object_id,action,author_id,changes,created_at FROM balance_audit_events WHERE company_id=? AND object_type=? AND object_id=? ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET '.(($page - 1) * 50), $params);

            return $this->page($items, $total, $page);
        });
    }

    private function assertPage(int $page, int $total, int $perPage = 50): void
    {
        $pages = max(1, intdiv($total, $perPage) + (0 === $total % $perPage ? 0 : 1));
        if ($page > $pages) {
            throw new BalanceLedgerException('Запрошенная страница не существует.', 422);
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     *
     * @return array<string,mixed>
     */
    private function page(array $items, int $total, int $page): array
    {
        $pager = new \Pagerfanta\Pagerfanta(new \Pagerfanta\Adapter\FixedAdapter($total, $items));
        $pager->setMaxPerPage(50);
        try {
            $pager->setCurrentPage($page);
        } catch (\Pagerfanta\Exception\OutOfRangeCurrentPageException) {
            throw new BalanceLedgerException('Запрошенная страница не существует.', 422);
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => 50, 'pager' => $pager];
    }

    private function assertId(string $id, int $status = 404): void
    {
        if (!Uuid::isValid($id)) {
            throw new BalanceLedgerException(404 === $status ? 'Объект учета не найден.' : 'Некорректный идентификатор в фильтре.', $status);
        }
    }

    public static function date(string $date): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Moscow'));
        if (false === $value || $value->format('Y-m-d') !== $date) {
            throw new BalanceLedgerException('Некорректная дата.');
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function card(string $companyId, string $id, string $from, string $to, bool $article, int $page, int $perPage): array
    {
        return $this->snapshot(function () use ($companyId, $id, $from, $to, $article, $page, $perPage): array {
            $statement = $this->statement($companyId, $from, $to);
            $ids = $article ? $this->db->fetchFirstColumn('WITH RECURSIVE tree AS (SELECT id FROM balance_articles WHERE company_id=:company AND id=:id UNION ALL SELECT c.id FROM balance_articles c JOIN tree t ON c.parent_id=t.id WHERE c.company_id=:company) SELECT a.id FROM balance_accounts a JOIN tree t ON a.article_id=t.id WHERE a.company_id=:company', ['company' => $companyId, 'id' => $id]) : [$id];
            $rows = array_values(array_filter($statement['accounts'], static fn (array $r): bool => in_array($r['id'], $ids, true)));
            if ([] === $rows && (!$article || !$this->db->fetchOne('SELECT id FROM balance_articles WHERE company_id=? AND id=?', [$companyId, $id]))) {
                throw new BalanceLedgerException('Счет или статья не найдены.', 404);
            }
            $opening = '0';
            $closing = '0';
            foreach ($rows as $r) {
                $opening = bcadd($opening, $r['opening'], 0);
                $closing = bcadd($closing, $r['closing'], 0);
            }
            $params = ['company' => $companyId, 'from' => $statement['effective_from'] ?? $from, 'to' => $to, 'accounts' => $ids];
            $types = ['accounts' => \Doctrine\DBAL\ArrayParameterType::STRING];
            $source = "FROM balance_operations o JOIN balance_operation_lines l ON l.company_id=o.company_id AND l.operation_id=o.id JOIN balance_accounts a ON a.company_id=l.company_id AND a.id=l.account_id WHERE o.company_id=:company AND o.status='posted' AND o.kind<>'opening' AND o.operation_date BETWEEN :from AND :to AND l.account_id IN (:accounts)";
            $total = (int) $this->db->fetchOne('SELECT COUNT(*) '.$source, $params, $types);
            $page = max(1, $page);
            $perPage = max(1, min(200, $perPage));
            $this->assertPage($page, $total, $perPage);
            $params['opening'] = $opening;
            $entries = $this->db->fetchAllAssociative('WITH entries AS (SELECT o.id,o.number,o.operation_date,o.posting_sequence,o.kind,o.reason,l.id AS line_id,l.account_id,a.name AS account_name,l.direction,l.amount::text AS amount '.$source."), deltas AS (SELECT id,operation_date,posting_sequence,SUM(CASE WHEN direction='increase' THEN amount::numeric ELSE -amount::numeric END) AS delta FROM entries GROUP BY id,operation_date,posting_sequence), running AS (SELECT id,CAST(:opening AS numeric)+SUM(delta) OVER(ORDER BY operation_date,posting_sequence ROWS UNBOUNDED PRECEDING) AS balance FROM deltas) SELECT e.id,e.number,e.operation_date,e.kind,e.reason,e.account_id,e.account_name,e.direction,e.amount,r.balance::text AS balance FROM entries e JOIN running r ON r.id=e.id ORDER BY e.operation_date,e.posting_sequence,e.line_id LIMIT ".$perPage.' OFFSET '.(($page - 1) * $perPage), $params, $types);
            foreach ($entries as $entry) {
                LedgerAmount::assertRange($entry['balance']);
            }
            $pager = new \Pagerfanta\Pagerfanta(new \Pagerfanta\Adapter\FixedAdapter($total, $entries));
            $pager->setMaxPerPage($perPage);
            $pager->setCurrentPage($page);

            LedgerAmount::assertRange($opening);
            LedgerAmount::assertRange($closing);

            return ['accounts' => $rows, 'opening' => $opening, 'closing' => $closing, 'entries' => $entries, 'from' => $from, 'to' => $to, 'effective_from' => $statement['effective_from'], 'accounting_started' => $statement['accounting_started'], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pager' => $pager];
        });
    }

    /**
     * @template T
     *
     * @param callable():T $read
     *
     * @return T
     */
    private function snapshot(callable $read): mixed
    {
        $native = $this->db->getNativeConnection();
        if ($this->db->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            return $read();
        }

        return $this->db->transactional(function () use ($read): mixed {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read();
        });
    }
}
