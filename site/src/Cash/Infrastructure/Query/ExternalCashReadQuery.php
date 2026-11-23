<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use App\Cash\Enum\Transaction\CashDirection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webmozart\Assert\Assert;

/**
 * @phpstan-type ExternalCashTransaction array{
 *     id: string, date: string, moneyAccountId: string, direction: string, amount: string, currency: string,
 *     description: ?string, isTransfer: bool, splits: list<array{categoryId: string, amount: string}>, importSource: ?string
 * }
 */
final readonly class ExternalCashReadQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, name: string, currency: string}> */
    public function accounts(string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        $rows = $this->references(
            'SELECT ref.id, ref.name, ref.currency FROM money_account ref WHERE ref.company_id = :company AND ref.is_active',
            $companyId,
            $search,
            $afterId,
            $fetchLimit,
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'currency' => (string) $row['currency'],
        ], $rows);
    }

    /** @return list<array{id: string, name: string, parentId: ?string}> */
    public function categories(string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        $rows = $this->references(
            'SELECT ref.id, ref.name, parent.id AS parent_id FROM cashflow_categories ref
             LEFT JOIN cashflow_categories parent ON parent.id = ref.parent_id AND parent.company_id = ref.company_id
             WHERE ref.company_id = :company',
            $companyId,
            $search,
            $afterId,
            $fetchLimit,
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'parentId' => null === $row['parent_id'] ? null : (string) $row['parent_id'],
        ], $rows);
    }

    /**
     * @param array{date: string, id: string}|null $after
     *
     * @return list<ExternalCashTransaction>
     */
    public function transactions(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?string $accountId,
        ?string $categoryId,
        ?CashDirection $direction,
        ?array $after,
        int $fetchLimit,
    ): array {
        Assert::uuid($companyId);
        Assert::range($fetchLimit, 1, 101);
        $dateFrom = $from->format('Y-m-d');
        $dateTo = $to->format('Y-m-d');
        if ($dateFrom > $dateTo || (new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days > 365) {
            throw new \InvalidArgumentException('Укажите период до 366 календарных дней.');
        }

        $query = $this->connection->createQueryBuilder()
            ->select('t.id', 't.occurred_at', 't.money_account_id', 't.direction', 't.amount', 't.currency',
                't.description', 't.is_transfer', 't.import_source', 'header.id AS header_category_id')
            ->from('cash_transaction', 't')
            ->innerJoin('t', 'money_account', 'a', 'a.id = t.money_account_id AND a.company_id = t.company_id')
            ->leftJoin('t', 'cashflow_categories', 'header', 'header.id = t.cashflow_category_id AND header.company_id = t.company_id')
            ->where('t.company_id = :company', 't.deleted_at IS NULL', 't.occurred_at BETWEEN :dateFrom AND :dateTo')
            ->setParameter('company', $companyId)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo)
            ->orderBy('t.occurred_at')
            ->addOrderBy('t.id')
            ->setMaxResults($fetchLimit);

        if (null !== $accountId) {
            $query->andWhere('t.money_account_id = :account')->setParameter('account', $accountId);
        }
        if (null !== $categoryId) {
            $query->andWhere(<<<'SQL'
                EXISTS (
                    SELECT 1 FROM cash_transaction_split s
                    WHERE s.cash_transaction_id = t.id AND s.company_id = t.company_id AND s.cashflow_category_id = :category
                ) OR (
                    header.id = :category AND NOT EXISTS (
                        SELECT 1 FROM cash_transaction_split s
                        WHERE s.cash_transaction_id = t.id AND s.company_id = t.company_id
                    )
                )
                SQL)->setParameter('category', $categoryId);
        }
        if (null !== $direction) {
            $query->andWhere('t.direction = :direction')->setParameter('direction', $direction->value);
        }
        if (null !== $after) {
            Assert::uuid($after['id']);
            $query->andWhere('(t.occurred_at, t.id) > (:afterDate, :afterId)')
                ->setParameter('afterDate', $after['date'])
                ->setParameter('afterId', $after['id']);
        }

        // A single snapshot covers both the page and its splits.
        $rows = $this->connection->fetchAllAssociative(
            'WITH page AS ('.$query->getSQL().') '.<<<'SQL'
                SELECT p.id, p.occurred_at, p.money_account_id, p.direction, p.amount, p.currency, p.description,
                       p.is_transfer, p.import_source, p.header_category_id, details.splits
                FROM page p
                LEFT JOIN LATERAL (
                    SELECT json_agg(json_build_object('categoryId', c.id, 'amount', CAST(s.amount AS TEXT))
                            ORDER BY c.id) AS splits
                    FROM cash_transaction_split s
                    JOIN cashflow_categories c ON c.id = s.cashflow_category_id AND c.company_id = s.company_id
                    WHERE s.cash_transaction_id = p.id AND s.company_id = :company
                ) details ON true
                ORDER BY p.occurred_at, p.id
                SQL,
            $query->getParameters(),
        );

        return array_map(self::transactionFromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return ExternalCashTransaction
     */
    private static function transactionFromRow(array $row): array
    {
        /** @var list<array{categoryId: string, amount: string}> $splits */
        $splits = match (true) {
            null !== $row['splits'] => json_decode((string) $row['splits'], true, flags: \JSON_THROW_ON_ERROR),
            null !== $row['header_category_id'] => [['categoryId' => (string) $row['header_category_id'], 'amount' => (string) $row['amount']]],
            default => [],
        };

        return [
            'id' => (string) $row['id'],
            'date' => (string) $row['occurred_at'],
            'moneyAccountId' => (string) $row['money_account_id'],
            'direction' => (string) $row['direction'],
            'amount' => (string) $row['amount'],
            'currency' => (string) $row['currency'],
            'description' => null === $row['description'] ? null : (string) $row['description'],
            'isTransfer' => (bool) $row['is_transfer'],
            'splits' => $splits,
            'importSource' => null === $row['import_source'] ? null : (string) $row['import_source'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function references(string $sql, string $companyId, ?string $search, ?string $afterId, int $fetchLimit): array
    {
        Assert::uuid($companyId);
        Assert::range($fetchLimit, 1, 101);
        $parameters = ['company' => $companyId, 'limit' => $fetchLimit];

        if (null !== $search) {
            $sql .= " AND ref.name ILIKE :search ESCAPE '!'";
            $parameters['search'] = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        if (null !== $afterId) {
            Assert::uuid($afterId);
            $sql .= ' AND ref.id > :after';
            $parameters['after'] = $afterId;
        }

        return $this->connection->fetchAllAssociative($sql.' ORDER BY ref.id LIMIT :limit', $parameters, ['limit' => ParameterType::INTEGER]);
    }
}
