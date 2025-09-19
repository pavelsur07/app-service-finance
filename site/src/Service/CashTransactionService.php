<?php

namespace App\Service;

use App\DTO\CashTransactionDTO;
use App\Entity\CashflowCategory;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\ProjectDirection;
use App\Exception\CurrencyMismatchException;
use App\Repository\CashTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;

class CashTransactionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountBalanceService $balanceService,
        private CashTransactionRepository $txRepo,
    ) {
    }

    /**
     * @throws ORMException
     */
    public function add(CashTransactionDTO $dto): CashTransaction
    {
        $company = $this->em->getReference(Company::class, $dto->companyId);
        $account = $this->em->getReference(MoneyAccount::class, $dto->moneyAccountId);
        if ($dto->currency !== $account->getCurrency()) {
            throw new CurrencyMismatchException();
        }
        $tx = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $dto->direction,
            $dto->amount,
            $dto->currency,
            $dto->occurredAt
        );

        $counterparty = $dto->counterpartyId
            ? $this->em->getReference(Counterparty::class, $dto->counterpartyId)
            : null;
        $category = $dto->cashflowCategoryId
            ? $this->em->getReference(CashflowCategory::class, $dto->cashflowCategoryId)
            : null;
        $projectDirection = $dto->projectDirectionId
            ? $this->em->getReference(ProjectDirection::class, $dto->projectDirectionId)
            : null;

        $tx
            ->setDescription($dto->description)
            ->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection);

        if ($dto->externalId) {
            $tx->setExternalId($dto->externalId);
        }

        $this->em->persist($tx);
        $from = $dto->occurredAt->setTime(0, 0);
        $to = new \DateTimeImmutable('today');
        $this->em->flush();
        $this->balanceService->recalculateDailyRange($company, $account, $from, $to);

        return $tx;
    }

    public function update(CashTransaction $tx, CashTransactionDTO $dto): CashTransaction
    {
        $company = $tx->getCompany();
        $oldAccount = $tx->getMoneyAccount();
        $oldDate = $tx->getOccurredAt();
        $account = $this->em->getReference(MoneyAccount::class, $dto->moneyAccountId);
        if ($dto->currency !== $account->getCurrency()) {
            throw new CurrencyMismatchException();
        }
        $tx->setMoneyAccount($account)
            ->setDirection($dto->direction)
            ->setAmount($dto->amount)
            ->setCurrency($dto->currency)
            ->setOccurredAt($dto->occurredAt)
            ->setDescription($dto->description);

        $counterparty = $dto->counterpartyId ? $this->em->getReference(Counterparty::class, $dto->counterpartyId) : null;
        $category = $dto->cashflowCategoryId ? $this->em->getReference(CashflowCategory::class, $dto->cashflowCategoryId) : null;
        $projectDirection = $dto->projectDirectionId ? $this->em->getReference(ProjectDirection::class, $dto->projectDirectionId) : null;
        $tx->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection);

        $this->em->flush();

        $from = min($dto->occurredAt, $oldDate)->setTime(0, 0);
        $to = new \DateTimeImmutable('today');
        $this->balanceService->recalculateDailyRange($company, $oldAccount, $from, $to);
        if ($oldAccount !== $account) {
            $this->balanceService->recalculateDailyRange($company, $account, $from, $to);
        }

        return $tx;
    }

    public function delete(CashTransaction $tx): void
    {
        $company = $tx->getCompany();
        $account = $tx->getMoneyAccount();
        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = new \DateTimeImmutable('today');
        $this->em->remove($tx);
        $this->em->flush();
        $this->balanceService->recalculateDailyRange($company, $account, $from, $to);
    }

    /**
     * Temporary method used during debugging to wipe all transactions and
     * rebuild account balances from scratch.
     */
    public function clearAll(): void
    {
        // remove all transactions and existing daily balance snapshots
        $this->em->createQuery('DELETE FROM App\\Entity\\CashTransaction t')->execute();
        $this->em->createQuery('DELETE FROM App\\Entity\\MoneyAccountDailyBalance b')->execute();

        // recalculate balances for every account to ensure snapshots are
        // recreated with opening values
        $accounts = $this->em->getRepository(MoneyAccount::class)->findAll();
        $today = new \DateTimeImmutable('today');
        foreach ($accounts as $account) {
            /** @var MoneyAccount $account */
            $company = $account->getCompany();
            $this->balanceService->recalculateDailyRange($company, $account, $today, $today);
        }
    }
}
