<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Entity\BankAccount;
use App\BankSync\Repository\BankAccountRepository;
use App\Cash\Facade\CashFacade;

/**
 * Какие денежные счета можно выбрать для счёта банка: активные счета компании той же
 * валюты, не привязанные к другому счёту банка (свой текущий — можно). Подсказка — счёт
 * с теми же цифрами номера; сохраняет только клиент.
 */
final readonly class BankAccountLinkChoices
{
    public function __construct(
        private CashFacade $cashFacade,
        private BankAccountRepository $accounts,
    ) {
    }

    /**
     * @param list<BankAccount> $bankAccounts
     *
     * @return array<string, array{choices: array<string, string>, suggested: string|null}> по id счёта банка: название => id денежного счёта
     */
    public function forAccounts(string $companyId, array $bankAccounts): array
    {
        $taken = array_fill_keys($this->accounts->findLinkedMoneyAccountIds($companyId), true);
        $candidatesByCurrency = [];
        $result = [];
        foreach ($bankAccounts as $bankAccount) {
            $currency = $bankAccount->getCurrency();
            $candidatesByCurrency[$currency] ??= $this->cashFacade->listStatementLinkCandidates($companyId, $currency);

            $choices = [];
            $suggested = null;
            $digits = preg_replace('/\D+/', '', $bankAccount->getAccountNumber());
            foreach ($candidatesByCurrency[$currency] as $candidate) {
                $own = $candidate->moneyAccountId === $bankAccount->getMoneyAccountId();
                if (isset($taken[$candidate->moneyAccountId]) && !$own) {
                    continue;
                }
                $choices[$candidate->name] = $candidate->moneyAccountId;
                if (null === $suggested && null !== $candidate->accountNumberDigits && $candidate->accountNumberDigits === $digits) {
                    $suggested = $candidate->moneyAccountId;
                }
            }
            $result[$bankAccount->getId()] = ['choices' => $choices, 'suggested' => $bankAccount->getMoneyAccountId() ?? $suggested];
        }

        return $result;
    }
}
