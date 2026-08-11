<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Analytics\Infrastructure\Cache\SnapshotCacheInvalidator;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\DailyBalanceRecalculator;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Exception\FinancePeriodLockedException;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Company\Entity\Company;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final readonly class CashTransferLifecycleAction
{
    public function __construct(
        private CashTransferRepository $transferRepository,
        private AutoRuleDispatchGuard $autoRuleDispatchGuard,
        private DailyBalanceRecalculator $recalculator,
        private SnapshotCacheInvalidator $snapshotCacheInvalidator,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(
        string $companyId,
        string $transferId,
        ?string $actorUserId = null,
        ?string $reason = null,
    ): void {
        $this->changeDeletionState($companyId, $transferId, true, $actorUserId, $reason);
    }

    public function restore(string $companyId, string $transferId, ?string $actorUserId = null): void
    {
        $this->changeDeletionState($companyId, $transferId, false, $actorUserId);
    }

    private function changeDeletionState(
        string $companyId,
        string $transferId,
        bool $delete,
        ?string $actorUserId,
        ?string $reason = null,
    ): void {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($transferId)) {
            throw new \DomainException('Перевод не найден.');
        }

        $preflightTransfer = $this->transferRepository->findOneByIdAndCompanyId($transferId, $companyId)
            ?? throw new \DomainException('Перевод не найден.');
        $this->assertConsistentState($preflightTransfer, $delete);
        $this->assertPeriodIsOpen(
            $preflightTransfer->getCompany(),
            $preflightTransfer->getSourceTransaction()->getOccurredAt(),
        );

        $company = $this->autoRuleDispatchGuard->suppress(
            fn (): Company => $this->entityManager->wrapInTransaction(
                function () use ($companyId, $transferId, $delete, $actorUserId, $reason): Company {
                    $transfer = $this->transferRepository->findOneByIdAndCompanyIdForUpdate($transferId, $companyId)
                        ?? throw new \DomainException('Перевод не найден.');
                    $this->assertConsistentState($transfer, $delete);

                    $company = $transfer->getCompany();
                    $this->assertPeriodIsOpen($company, $transfer->getSourceTransaction()->getOccurredAt());

                    if ($delete) {
                        $transfer->markDeleted($actorUserId, $reason);
                        $transfer->getSourceTransaction()->markDeleted($actorUserId, $reason);
                        $transfer->getTargetTransaction()->markDeleted($actorUserId, $reason);
                    } else {
                        $transfer->restore();
                        $transfer->getSourceTransaction()->restore();
                        $transfer->getTargetTransaction()->restore();
                    }

                    $this->entityManager->persist(new AuditLog(
                        $companyId,
                        CashTransfer::class,
                        $transfer->getId(),
                        $delete ? AuditLogAction::SOFT_DELETE : AuditLogAction::RESTORE,
                        null,
                        $actorUserId,
                    ));
                    foreach ([$transfer->getSourceTransaction(), $transfer->getTargetTransaction()] as $transaction) {
                        $this->entityManager->persist(new AuditLog(
                            $companyId,
                            CashTransaction::class,
                            (string) $transaction->getId(),
                            $delete ? AuditLogAction::SOFT_DELETE : AuditLogAction::RESTORE,
                            null,
                            $actorUserId,
                        ));
                    }
                    $this->entityManager->flush();

                    $accountIds = [
                        (string) $transfer->getSourceTransaction()->getMoneyAccount()->getId(),
                        (string) $transfer->getTargetTransaction()->getMoneyAccount()->getId(),
                    ];
                    sort($accountIds, \SORT_STRING);
                    foreach ($accountIds as $accountId) {
                        $this->recalculator->recalcRange(
                            $company,
                            $transfer->getSourceTransaction()->getOccurredAt()->setTime(0, 0),
                            (new \DateTimeImmutable('today'))->setTime(0, 0),
                            [$accountId],
                        );
                    }

                    return $company;
                },
            ),
        );

        $this->snapshotCacheInvalidator->invalidateForCompany($company);
    }

    private function assertConsistentState(CashTransfer $transfer, bool $delete): void
    {
        $sourceDeleted = $transfer->getSourceTransaction()->isDeleted();
        $targetDeleted = $transfer->getTargetTransaction()->isDeleted();

        if ($delete && $transfer->isDeleted()) {
            throw new \DomainException('Перевод уже удалён.');
        }
        if (!$delete && !$transfer->isDeleted()) {
            throw new \DomainException('Перевод не удалён.');
        }
        if ($delete && ($sourceDeleted || $targetDeleted)) {
            throw new \DomainException('Состояние ног перевода нарушено; удаление остановлено.');
        }
        if (!$delete && (!$sourceDeleted || !$targetDeleted)) {
            throw new \DomainException('Состояние ног перевода нарушено; восстановление остановлено.');
        }
    }

    private function assertPeriodIsOpen(Company $company, \DateTimeImmutable $occurredAt): void
    {
        $lock = $company->getFinanceLockBefore()?->setTime(0, 0);
        if (null !== $lock && $occurredAt->setTime(0, 0) < $lock) {
            throw new FinancePeriodLockedException(sprintf('Период закрыт. Операции с датами ранее %s запрещены.', $lock->format('d.m.Y')));
        }
    }
}
