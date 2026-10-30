<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * @extends ServiceEntityRepository<CashCategorySuggestion>
 */
final class CashCategorySuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashCategorySuggestion::class);
    }

    public function findOneForCompany(string $companyId, string $id): ?CashCategorySuggestion
    {
        Assert::uuid($companyId);
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['companyId' => $companyId, 'id' => $id]);
    }

    /**
     * Строки задачи, ждущие ответа.
     *
     * @return list<CashCategorySuggestion>
     */
    public function findRequestedByTask(string $companyId, string $taskId): array
    {
        Assert::uuid($companyId);
        Assert::uuid($taskId);

        return $this->findBy(['companyId' => $companyId, 'taskId' => $taskId, 'status' => Status::REQUESTED]);
    }

    /**
     * Зависшие постановки: ответа нет дольше порога (сбой между commit и отправкой, задача потеряна).
     *
     * @return list<CashCategorySuggestion>
     */
    public function findRequestedBefore(string $companyId, \DateTimeImmutable $before, int $limit): array
    {
        Assert::uuid($companyId);

        /* @var list<CashCategorySuggestion> */
        return $this->createQueryBuilder('s')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.status = :status')
            ->andWhere('s.requestedAt < :before')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', Status::REQUESTED)
            ->setParameter('before', $before)
            ->orderBy('s.requestedAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
