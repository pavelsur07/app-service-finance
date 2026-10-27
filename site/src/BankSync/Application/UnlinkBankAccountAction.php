<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Exception\BankAccountNotFoundException;
use App\BankSync\Repository\BankAccountRepository;
use App\Cash\Facade\CashFacade;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Отвязка счёта банка: выписка в денежный счёт больше не грузится, признак «выписка из
 * банка по API» снимается в той же транзакции. Курсор счёта остаётся — повторная привязка
 * продолжит с него. Повтор отвязки — без изменений.
 */
final readonly class UnlinkBankAccountAction
{
    public function __construct(
        private BankAccountRepository $accounts,
        private CashFacade $cashFacade,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BankAccountNotFoundException счёт банка не принадлежит компании
     */
    public function __invoke(string $companyId, string $bankAccountId): void
    {
        $account = $this->accounts->findOneByIdAndCompanyId($bankAccountId, $companyId)
            ?? throw new BankAccountNotFoundException($bankAccountId);

        $unlinked = $this->entityManager->wrapInTransaction(function () use ($account, $companyId): ?string {
            $this->entityManager->refresh($account, LockMode::PESSIMISTIC_WRITE);
            $previous = $account->getMoneyAccountId();
            if (null === $previous) {
                return null;
            }
            $account->unlink();
            $this->cashFacade->clearBankApiStatementSource($companyId, $previous);

            return $previous;
        });

        if (null !== $unlinked) {
            $this->logger->info('Bank account unlinked from money account', [
                'company_id' => $companyId,
                'bank_account_id' => $bankAccountId,
                'money_account_id' => $unlinked,
            ]);
        }
    }
}
