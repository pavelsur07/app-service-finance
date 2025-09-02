<?php

namespace App\Service;

use App\DTO\CashTransactionDTO;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Exception\CurrencyMismatchException;
use App\Repository\CashTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class CashTransactionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountBalanceService $balanceService,
        private CashTransactionRepository $txRepo
    ) {}

    public function add(CashTransactionDTO $dto): CashTransaction
    {
        $company = $this->em->getReference(Company::class, $dto->companyId);
        $account = $this->em->getReference(MoneyAccount::class, $dto->moneyAccountId);
        if ($dto->currency !== $account->getCurrency()) {
            throw new CurrencyMismatchException();
        }
        $tx = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, $dto->direction, $dto->amount, $dto->currency, $dto->occurredAt);
        $this->em->persist($tx);
        $from = $dto->occurredAt->setTime(0,0);
        $to = new \DateTimeImmutable('today');
        $this->em->flush();
        $this->balanceService->recalculateDailyRange($company, $account, $from, $to);
        return $tx;
    }
}
