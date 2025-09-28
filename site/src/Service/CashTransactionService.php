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
        private DailyBalanceRecalculator $recalculator,
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
        $this->em->flush();

        // Пересчёт только по затронутому счёту и датам (как в команде)
        $from = $dto->occurredAt->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);

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

        $counterparty = $dto->counterpartyId
            ? $this->em->getReference(Counterparty::class, $dto->counterpartyId)
            : null;
        $category = $dto->cashflowCategoryId
            ? $this->em->getReference(CashflowCategory::class, $dto->cashflowCategoryId)
            : null;
        $projectDirection = $dto->projectDirectionId
            ? $this->em->getReference(ProjectDirection::class, $dto->projectDirectionId)
            : null;

        $tx->setCounterparty($counterparty)
            ->setCashflowCategory($category)
            ->setProjectDirection($projectDirection);

        $this->em->flush();

        // Пересчитываем минимально необходимый диапазон
        $from = min($dto->occurredAt, $oldDate)->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        // Старый счёт
        $this->recalculator->recalcRange($company, $from, $to, [$oldAccount->getId()]);

        // Если счёт изменился — ещё и новый
        if ($oldAccount->getId() !== $account->getId()) {
            $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
        }

        return $tx;
    }

    public function delete(CashTransaction $tx): void
    {
        $company = $tx->getCompany();
        $account = $tx->getMoneyAccount();

        $from = $tx->getOccurredAt()->setTime(0, 0);
        $to = (new \DateTimeImmutable('today'))->setTime(0, 0);

        $this->em->remove($tx);
        $this->em->flush();

        // Пересчёт по затронутому счёту
        $this->recalculator->recalcRange($company, $from, $to, [$account->getId()]);
    }
}
