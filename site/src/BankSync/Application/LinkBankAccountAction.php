<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Exception\BankAccountNotFoundException;
use App\BankSync\Exception\MoneyAccountAlreadyLinkedException;
use App\BankSync\Exception\MoneyAccountNotLinkableException;
use App\BankSync\Repository\BankAccountRepository;
use App\BankSync\Repository\BankConnectionRepository;
use App\Cash\Facade\CashFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Привязка счёта банка к денежному счёту компании (или перепривязка на другой).
 *
 * Одна транзакция: строка счёта банка `FOR UPDATE` → привязка → признак «выписка из банка
 * по API» снимается с прежнего денежного счёта и ставится на новый (`CashFacade`, без
 * commit). Один денежный счёт — один счёт банка: держит частичный unique; проигравший
 * гонку получает `MoneyAccountAlreadyLinkedException`, а повтор той же привязки — тот же
 * результат без изменений.
 */
final readonly class LinkBankAccountAction
{
    public function __construct(
        private BankAccountRepository $accounts,
        private BankConnectionRepository $connections,
        private CashFacade $cashFacade,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BankAccountNotFoundException счёт банка не принадлежит компании
     * @throws MoneyAccountNotLinkableException денежный счёт чужой, неактивный или в другой валюте
     * @throws MoneyAccountAlreadyLinkedException денежный счёт привязан к другому счёту банка
     */
    public function __invoke(string $companyId, string $bankAccountId, string $moneyAccountId): void
    {
        $account = $this->accounts->findOneByIdAndCompanyId($bankAccountId, $companyId)
            ?? throw new BankAccountNotFoundException($bankAccountId);
        $target = $this->cashFacade->getStatementImportAccount($companyId, $moneyAccountId)
            ?? throw new MoneyAccountNotLinkableException(MoneyAccountNotLinkableException::NOT_FOUND);
        if ($target->currency !== $account->getCurrency()) {
            throw new MoneyAccountNotLinkableException(MoneyAccountNotLinkableException::CURRENCY);
        }
        $bankCode = $this->connections->findOneByIdAndCompanyId($account->getConnectionId(), $companyId)?->getBankCode()
            ?? throw new BankAccountNotFoundException($bankAccountId);
        // Частый отказ — до транзакции: исключение внутри wrapInTransaction закрывает EM.
        $holder = $this->accounts->findOneByMoneyAccount($companyId, $moneyAccountId);
        if (null !== $holder && $holder->getId() !== $account->getId()) {
            throw new MoneyAccountAlreadyLinkedException($moneyAccountId);
        }

        try {
            $this->entityManager->wrapInTransaction(function () use ($account, $companyId, $moneyAccountId, $bankCode): void {
                $this->entityManager->refresh($account, LockMode::PESSIMISTIC_WRITE);
                $previous = $account->getMoneyAccountId();
                if ($previous === $moneyAccountId) {
                    return;
                }
                $holder = $this->accounts->findOneByMoneyAccount($companyId, $moneyAccountId);
                if (null !== $holder && $holder->getId() !== $account->getId()) {
                    throw new MoneyAccountAlreadyLinkedException($moneyAccountId);
                }

                $account->linkTo($moneyAccountId);
                // Cash блокирует денежный счёт при изменении признака: два счёта — в порядке id,
                // иначе встречная перепривязка (M1 → M2 и M2 → M1) даёт взаимную блокировку.
                $changes = [$moneyAccountId => fn () => $this->cashFacade->markBankApiStatementSource($companyId, $moneyAccountId, $bankCode->value)];
                if (null !== $previous) {
                    $changes[$previous] = fn () => $this->cashFacade->clearBankApiStatementSource($companyId, $previous);
                }
                ksort($changes, \SORT_STRING);
                foreach ($changes as $change) {
                    $change();
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельная привязка того же денежного счёта успела раньше: EM закрыт, смотрим
            // результат напрямую. Наш же счёт банка — двойная отправка формы, результат тот же.
            $holderId = $this->entityManager->getConnection()->fetchOne(
                'SELECT id FROM bank_sync_account WHERE company_id = :companyId AND money_account_id = :moneyAccountId',
                ['companyId' => $companyId, 'moneyAccountId' => $moneyAccountId],
            );
            if ($holderId === $bankAccountId) {
                return;
            }

            throw new MoneyAccountAlreadyLinkedException($moneyAccountId);
        }

        $this->logger->info('Bank account linked to money account', [
            'company_id' => $companyId,
            'bank_account_id' => $bankAccountId,
            'money_account_id' => $moneyAccountId,
        ]);
    }
}
