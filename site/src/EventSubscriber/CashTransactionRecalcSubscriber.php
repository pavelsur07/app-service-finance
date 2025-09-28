<?php

namespace App\EventSubscriber;

use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Service\DailyBalanceRecalculator;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

class CashTransactionRecalcSubscriber implements EventSubscriber
{
    /**
     * @var array<string, array{company: Company, account: MoneyAccount, from: \DateTimeImmutable, to: \DateTimeImmutable}>
     */
    private array $pendingRanges = [];

    /**
     * @var array<string, ?\DateTimeImmutable>
     */
    private array $maxDateCache = [];

    public function __construct(
        private readonly DailyBalanceRecalculator $recalculator,
        private readonly MoneyAccountDailyBalanceRepository $dailyRepo,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::onFlush,
            Events::postFlush,
        ];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CashTransaction) {
                $this->handleInsertion($entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof CashTransaction) {
                $this->handleUpdate($entity, $uow, $em);
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof CashTransaction) {
                $this->handleDeletion($entity);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendingRanges === []) {
            return;
        }

        foreach ($this->pendingRanges as $range) {
            $this->recalculator->recalcRange(
                $range['company'],
                $range['from'],
                $range['to'],
                [$range['account']->getId()]
            );
        }

        $this->pendingRanges = [];
        $this->maxDateCache = [];
    }

    private function handleInsertion(CashTransaction $transaction): void
    {
        $company = $transaction->getCompany();
        $account = $transaction->getMoneyAccount();
        $occurred = $this->normalizeDate($transaction->getOccurredAt());
        if (null === $occurred) {
            return;
        }

        $maxDate = $this->getMaxBalanceDate($company, $account);
        $to = $maxDate ? $this->maxDate($occurred, $maxDate) : $occurred;

        $this->addRange($company, $account, $occurred, $to);
    }

    private function handleUpdate(CashTransaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        $company = $transaction->getCompany();
        $original = $uow->getOriginalEntityData($transaction);

        $oldDate = $this->normalizeDate($original['occurredAt'] ?? $transaction->getOccurredAt());
        $newDate = $this->normalizeDate($transaction->getOccurredAt());
        if (null === $oldDate || null === $newDate) {
            return;
        }

        $oldAccount = $this->resolveAccount($original['moneyAccount'] ?? $transaction->getMoneyAccount(), $em);
        $newAccount = $transaction->getMoneyAccount();
        if (!$oldAccount instanceof MoneyAccount || !$newAccount instanceof MoneyAccount) {
            return;
        }

        $from = $this->minDate($oldDate, $newDate);
        $maxFactsOld = $this->getMaxBalanceDate($company, $oldAccount);
        $toOld = $this->maxDate($this->maxDate($oldDate, $newDate), $maxFactsOld);
        $this->addRange($company, $oldAccount, $from, $toOld);

        if ($oldAccount->getId() !== $newAccount->getId()) {
            $maxFactsNew = $this->getMaxBalanceDate($company, $newAccount);
            $toNew = $this->maxDate($this->maxDate($oldDate, $newDate), $maxFactsNew);
            $this->addRange($company, $newAccount, $from, $toNew);
        }
    }

    private function handleDeletion(CashTransaction $transaction): void
    {
        $company = $transaction->getCompany();
        $account = $transaction->getMoneyAccount();
        $occurred = $this->normalizeDate($transaction->getOccurredAt());
        if (null === $occurred) {
            return;
        }

        $maxDate = $this->getMaxBalanceDate($company, $account);
        $to = $maxDate ? $this->maxDate($occurred, $maxDate) : $occurred;

        $this->addRange($company, $account, $occurred, $to);
    }

    private function addRange(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $companyId = $company->getId();
        $accountId = $account->getId();

        if (null === $companyId || null === $accountId) {
            return;
        }

        $key = $companyId.'__'.$accountId;

        if (!isset($this->pendingRanges[$key])) {
            $this->pendingRanges[$key] = [
                'company' => $company,
                'account' => $account,
                'from' => $from,
                'to' => $to,
            ];

            return;
        }

        $existing = $this->pendingRanges[$key];
        $this->pendingRanges[$key]['from'] = $this->minDate($existing['from'], $from);
        $this->pendingRanges[$key]['to'] = $this->maxDate($existing['to'], $to);
    }

    private function getMaxBalanceDate(Company $company, MoneyAccount $account): ?\DateTimeImmutable
    {
        $companyId = $company->getId();
        $accountId = $account->getId();
        if (null === $companyId || null === $accountId) {
            return null;
        }

        $key = $companyId.'__'.$accountId;
        if (!array_key_exists($key, $this->maxDateCache)) {
            $qb = $this->dailyRepo->createQueryBuilder('b')
                ->select('MAX(b.date)')
                ->where('b.company = :company')
                ->andWhere('b.moneyAccount = :account')
                ->setParameter('company', $company)
                ->setParameter('account', $account);

            $result = $qb->getQuery()->getSingleScalarResult();
            if ($result) {
                $this->maxDateCache[$key] = new \DateTimeImmutable($result);
            } else {
                $this->maxDateCache[$key] = null;
            }
        }

        return $this->maxDateCache[$key];
    }

    private function resolveAccount(mixed $value, EntityManagerInterface $em): ?MoneyAccount
    {
        if ($value instanceof MoneyAccount) {
            return $value;
        }

        if (null === $value) {
            return null;
        }

        if (is_string($value)) {
            return $em->getReference(MoneyAccount::class, $value);
        }

        return null;
    }

    private function normalizeDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->setTime(0, 0);
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (is_string($value) && $value !== '') {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        }

        return null;
    }

    private function minDate(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    private function maxDate(\DateTimeImmutable $a, ?\DateTimeImmutable $b): \DateTimeImmutable
    {
        if (null === $b) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }
}
