<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class BulkDeleteCashTransactionsAction
{
    private const MAX_TRANSACTION_COUNT = 20;

    public function __construct(
        private readonly CashTransactionRepository $transactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly SnapshotCacheInvalidator $snapshotCacheInvalidator,
    ) {
    }

    /**
     * @param mixed[] $transactionIds
     */
    public function __invoke(Company $company, array $transactionIds, ?string $actorUserId): int
    {
        $ids = $this->normalizeIds($transactionIds);
        $companyId = (string) $company->getId();

        $transactions = $this->transactionRepository->findActiveByIdsAndCompanyId($ids, $companyId);
        if (\count($transactions) !== \count($ids)) {
            throw new \DomainException('Не удалось удалить выбранные транзакции. Обновите страницу и повторите.');
        }

        $this->assertTransactionsAreUnlocked($company, $transactions);

        $from = null;
        $accountIds = [];
        foreach ($transactions as $transaction) {
            $occurredAt = $transaction->getOccurredAt()->setTime(0, 0);
            $from = null === $from || $occurredAt < $from ? $occurredAt : $from;
            $accountIds[$transaction->getMoneyAccount()->getId()] = true;
            // postUpdate наступает слишком поздно, чтобы новая запись subscriber-а
            // попала в текущий UnitOfWork, поэтому аудит пакета планируем до flush.
            $this->entityManager->persist(new AuditLog(
                $companyId,
                CashTransaction::class,
                (string) $transaction->getId(),
                AuditLogAction::SOFT_DELETE,
                null,
                $actorUserId,
            ));
            $transaction->markDeleted($actorUserId);
        }

        $this->entityManager->flush();

        \assert($from instanceof \DateTimeImmutable);
        $this->recalculator->recalcRange(
            $company,
            $from,
            (new \DateTimeImmutable('today'))->setTime(0, 0),
            array_keys($accountIds),
        );
        $this->snapshotCacheInvalidator->invalidateForCompany($company);

        return \count($transactions);
    }

    /**
     * @param mixed[] $transactionIds
     *
     * @return list<string>
     */
    private function normalizeIds(array $transactionIds): array
    {
        $ids = [];
        foreach ($transactionIds as $transactionId) {
            if (!\is_string($transactionId) || !Uuid::isValid($transactionId)) {
                throw new \DomainException('Некорректный список транзакций.');
            }

            $ids[$transactionId] = true;
        }

        if ([] === $ids) {
            throw new \DomainException('Выберите транзакции для удаления.');
        }
        if (\count($ids) > self::MAX_TRANSACTION_COUNT) {
            throw new \DomainException(sprintf('За один раз можно удалить не более %d транзакций.', self::MAX_TRANSACTION_COUNT));
        }

        return array_keys($ids);
    }

    /**
     * @param list<CashTransaction> $transactions
     */
    private function assertTransactionsAreUnlocked(Company $company, array $transactions): void
    {
        $lock = $company->getFinanceLockBefore()?->setTime(0, 0);
        if (null === $lock) {
            return;
        }

        foreach ($transactions as $transaction) {
            if ($transaction->getOccurredAt()->setTime(0, 0) < $lock) {
                throw new FinancePeriodLockedException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
            }
        }
    }
}
