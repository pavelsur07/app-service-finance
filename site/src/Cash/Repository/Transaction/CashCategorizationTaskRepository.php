<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashCategorizationTask;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashCategorizationTask>
 */
final class CashCategorizationTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashCategorizationTask::class);
    }

    /**
     * Задачи, которые ждут ответа, а последняя отправка была раньше `$before`.
     *
     * @return list<CashCategorizationTask>
     */
    public function findWaitingDispatchedBefore(string $companyId, \DateTimeImmutable $before, int $limit): array
    {
        Assert::uuid($companyId);

        /* @var list<CashCategorizationTask> */
        return $this->createQueryBuilder('t')
            ->andWhere('t.companyId = :companyId')
            ->andWhere('t.dispatchedAt < :before')
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s s WHERE s.taskId = t.id AND s.companyId = t.companyId AND s.status = :requested)',
                CashCategorySuggestion::class,
            ))
            ->setParameter('companyId', $companyId)
            ->setParameter('before', $before)
            ->setParameter('requested', Status::REQUESTED)
            ->orderBy('t.dispatchedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Задачи без строк `requested`: ответ получен или строки закрыты — payload больше не нужен.
     *
     * @return list<CashCategorizationTask>
     */
    public function findSettled(string $companyId, int $limit): array
    {
        Assert::uuid($companyId);

        /* @var list<CashCategorizationTask> */
        return $this->createQueryBuilder('t')
            ->andWhere('t.companyId = :companyId')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s s WHERE s.taskId = t.id AND s.companyId = t.companyId AND s.status = :requested)',
                CashCategorySuggestion::class,
            ))
            ->setParameter('companyId', $companyId)
            ->setParameter('requested', Status::REQUESTED)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
