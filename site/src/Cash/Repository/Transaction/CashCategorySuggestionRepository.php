<?php

declare(strict_types=1);

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /** Сколько подсказок компании ждут ответа ИИ (`requested`). */
    public function countRequested(string $companyId): int
    {
        Assert::uuid($companyId);

        return $this->count(['companyId' => $companyId, 'status' => Status::REQUESTED]);
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

    /** Сколько подсказок компании переоткроет reopenAfterTreeChange(). */
    public function countReopenable(string $companyId): int
    {
        Assert::uuid($companyId);

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count(*) FROM cash_category_suggestion s WHERE '.self::REOPENABLE,
            ...$this->reopenableParams($companyId, null),
        );
    }

    /**
     * Переоткрывает подсказки компании без решения после смены дерева статей (`no_answer` и
     * `suggested` у операции, всё ещё ждущей статью → `stale`, причина в `error_code`): ответ выбран из
     * старого дерева, операции снова кандидаты ИИ. `suggested` у разнесённой человеком операции — решение
     * правкой, история точности ИИ (ворота автоприменения, предложения правил): не трогается. Один
     * UPDATE без загрузки истории в память; строки блокируются им же. Только внутри транзакции
     * вызывающего — откатывается вместе с ней.
     *
     * @return int сколько переоткрыто
     */
    public function reopenAfterTreeChange(string $companyId, string $reason, \DateTimeImmutable $at): int
    {
        Assert::uuid($companyId);
        Assert::lengthBetween($reason, 1, 64);
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Переоткрытие подсказок ИИ — только внутри транзакции вызывающего.');
        }

        return (int) $connection->executeStatement(
            'UPDATE cash_category_suggestion s SET status = \'stale\', error_code = :reason, responded_at = COALESCE(s.responded_at, :at) WHERE '.self::REOPENABLE,
            ...$this->reopenableParams($companyId, ['reason' => $reason, 'at' => $at->format('Y-m-d H:i:s')]),
        );
    }

    private const REOPENABLE = <<<'SQL'
        s.company_id = :companyId AND (s.status = 'no_answer' OR (s.status = 'suggested' AND EXISTS (
            SELECT 1 FROM cash_transaction tx
            JOIN "companies" company ON company.id = tx.company_id
            LEFT JOIN "cashflow_categories" category ON category.id = tx.cashflow_category_id
            WHERE tx.id = s.transaction_id AND
        SQL.' '.CashCategorizationQuery::UNCATEGORIZED.')))';

    /**
     * @param array<string, string>|null $extra
     *
     * @return array{0: array<string, mixed>, 1: array<string, ArrayParameterType>}
     */
    private function reopenableParams(string $companyId, ?array $extra): array
    {
        return [
            ['companyId' => $companyId, 'unallocatedCodes' => [CashflowCategory::CODE_UNALLOCATED, CashflowCategory::SYSTEM_UNALLOCATED]] + ($extra ?? []),
            ['unallocatedCodes' => ArrayParameterType::STRING],
        ];
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
