<?php

declare(strict_types=1);

namespace App\Cash\Repository\PaymentIntake;

use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaymentIntakeDraft> */
final class PaymentIntakeDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentIntakeDraft::class);
    }

    public function getForCompany(string $companyId, string $id, bool $lock = false): ?PaymentIntakeDraft
    {
        $query = $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere('d.id = :id')->setParameter('company', $companyId)->setParameter('id', $id)->getQuery()->setHint(Query::HINT_REFRESH, true);
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getOneOrNullResult();
    }

    /** @return list<string> */
    public function getOpenIdsForActor(string $companyId, string $actorUserId): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn("SELECT id FROM cash_payment_intake_draft WHERE company_id = :company AND actor_user_id = :actor AND status NOT IN ('confirmed', 'cancelled') AND mcp_connection_id IS NULL ORDER BY id DESC LIMIT 200", ['company' => $companyId, 'actor' => $actorUserId]));
    }

    /** @return list<array{id: string, externalId: string, receivedAt: string}> */
    public function listMcpDrafts(string $companyId, string $actorUserId, ?string $beforeId, bool $closed): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id', 'mcp_external_id', 'received_at')
            ->from('cash_payment_intake_draft')
            ->where('company_id = :company', 'actor_user_id = :actor', 'mcp_connection_id IS NOT NULL')
            ->andWhere($closed ? "status IN ('confirmed', 'cancelled')" : "status NOT IN ('confirmed', 'cancelled')")
            ->setParameter('company', $companyId)
            ->setParameter('actor', $actorUserId)
            ->orderBy('id', 'DESC')
            ->setMaxResults(51);
        if (null !== $beforeId) {
            $query->andWhere('id < :before')->setParameter('before', $beforeId);
        }

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'externalId' => (string) $row['mcp_external_id'],
            'receivedAt' => (string) $row['received_at'],
        ], array_values($query->fetchAllAssociative()));
    }

    public function getMcpByExternalId(string $companyId, string $connectionId, string $externalId): ?PaymentIntakeDraft
    {
        return $this->createQueryBuilder('d')
            ->where('d.companyId = :company', 'd.mcpConnectionId = :connection', 'd.mcpExternalId = :external')
            ->setParameter('company', $companyId)
            ->setParameter('connection', $connectionId)
            ->setParameter('external', $externalId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function getByTask(string $companyId, string $taskId): ?PaymentIntakeDraft
    {
        return $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere('d.taskId = :task')->setParameter('company', $companyId)->setParameter('task', $taskId)->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    /** @companyScopeExempt Global Telegram source binding; caller authorizes exact actor and bound company before exposing data. */
    public function getBySource(string $sourceKey): ?PaymentIntakeDraft
    {
        return $this->createQueryBuilder('d')->where('d.sourceKey = :source')->andWhere('d.mcpConnectionId IS NULL')->setParameter('source', $sourceKey)->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    /** @companyScopeExempt Existence-only global source guard; returns no draft identity, content or tenant data. */
    public function hasSource(string $sourceKey): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_draft WHERE source_key = :source AND mcp_connection_id IS NULL', ['source' => $sourceKey]) > 0;
    }

    /**
     * @return list<string>
     *
     * @companyScopeExempt Technical enumeration of pending event company IDs only; recovery is company-scoped.
     */
    public function getPendingCashEventCompanyIds(): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT company_id FROM cash_payment_intake_draft WHERE cash_event_pending = true ORDER BY company_id'));
    }

    /** @return list<PaymentIntakeDraft> */
    public function getPendingCashEvents(string $companyId, ?string $afterId = null): array
    {
        $query = $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere('d.cashEventPending = true')->setParameter('company', $companyId)->orderBy('d.id', \SortDirection::Ascending)->setMaxResults(100);
        if (null !== $afterId) {
            $query->andWhere('d.id > :after')->setParameter('after', $afterId);
        }

        return $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
    }

    /** @return list<PaymentIntakeDraft> */
    public function getRecoverable(string $companyId, \DateTimeImmutable $now, int $limit = 100, ?string $afterId = null): array
    {
        $query = $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere("d.dispatchPending = true OR d.cashEventPending = true OR (d.status = 'queued' AND (d.lastEnqueuedAt IS NULL OR d.lastEnqueuedAt <= :retryBefore))")->setParameter('retryBefore', $now->modify('-60 seconds'), Types::DATETIMETZ_IMMUTABLE)->setParameter('company', $companyId)->orderBy('d.id', \SortDirection::Ascending)->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('d.id > :afterId')->setParameter('afterId', $afterId);
        }

        return $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
    }
}
