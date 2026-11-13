<?php

declare(strict_types=1);

namespace App\Cash\Repository\PaymentIntake;

use App\Cash\Entity\PaymentIntake\PaymentIntakeDraft;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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

    public function getByTask(string $companyId, string $taskId): ?PaymentIntakeDraft
    {
        return $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere('d.taskId = :task')->setParameter('company', $companyId)->setParameter('task', $taskId)->getQuery()->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
    }

    /** @companyScopeExempt Global Telegram source binding; caller authorizes exact actor and bound company before exposing data. */
    public function getBySource(string $sourceKey): ?PaymentIntakeDraft
    {
        return $this->createQueryBuilder('d')->where('d.sourceKey = :source')->setParameter('source', $sourceKey)->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
    }

    /** @return list<PaymentIntakeDraft> */
    public function getRecoverable(string $companyId, int $limit = 100, ?string $afterId = null): array
    {
        $query = $this->createQueryBuilder('d')->where('d.companyId = :company')->andWhere('d.dispatchPending = true OR d.cashEventPending = true')->setParameter('company', $companyId)->orderBy('d.id', \SortDirection::Ascending)->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('d.id > :afterId')->setParameter('afterId', $afterId);
        }

        return $query->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
    }
}
