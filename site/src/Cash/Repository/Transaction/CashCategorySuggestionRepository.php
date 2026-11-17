<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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
     * Подсказка под блокировкой строки: решение человека («Принять», «Отклонить») читает статус
     * только после захвата, иначе параллельный запрос перезапишет уже принятое решение.
     * Порядок блокировок сценария — подсказка, затем операция.
     */
    public function findOneForCompanyForUpdate(string $companyId, string $id): ?CashCategorySuggestion
    {
        $suggestion = $this->findOneForCompany($companyId, $id);
        if (null !== $suggestion) {
            // refresh, а не find с блокировкой: строка из identity map иначе осталась бы прочитанной до захвата.
            $this->getEntityManager()->refresh($suggestion, LockMode::PESSIMISTIC_WRITE);
        }

        return $suggestion;
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
     * Открытые подсказки (`suggested`), предлагающие одну из статей.
     *
     * @param list<string> $categoryIds
     *
     * @return list<CashCategorySuggestion>
     */
    public function findSuggestedForCategories(string $companyId, array $categoryIds): array
    {
        Assert::uuid($companyId);
        if ([] === $categoryIds) {
            return [];
        }

        return $this->findBy(['companyId' => $companyId, 'status' => Status::SUGGESTED, 'categoryId' => $categoryIds]);
    }

    /**
     * То же под FOR UPDATE со свежим состоянием: только внутри транзакции вызывающего.
     * Отклонённая параллельно подсказка в выборку не попадает — PostgreSQL перепроверяет
     * условие по строке, дождавшись чужого commit.
     *
     * @param list<string> $categoryIds
     *
     * @return list<CashCategorySuggestion>
     */
    public function findSuggestedForCategoriesForUpdate(string $companyId, array $categoryIds): array
    {
        Assert::uuid($companyId);
        if ([] === $categoryIds) {
            return [];
        }

        /** @var list<CashCategorySuggestion> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.status = :status')
            ->andWhere('s.categoryId IN (:categoryIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', Status::SUGGESTED)
            ->setParameter('categoryIds', $categoryIds)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /**
     * Были ли у компании запросы, истёкшие без ответа (`failed/expired`) с момента: сервис мог
     * выполнить их платно, а импорт — не дойти.
     */
    public function hasExpiredSince(string $companyId, \DateTimeImmutable $since): bool
    {
        Assert::uuid($companyId);

        return null !== $this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.status = :status')
            ->andWhere('s.errorCode = :expired')
            ->andWhere('s.respondedAt >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', Status::FAILED)
            ->setParameter('expired', 'expired')
            ->setParameter('since', $since)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Строки без ответа дольше порога (задача переотправлялась, ответа так и нет).
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
