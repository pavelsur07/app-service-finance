<?php

declare(strict_types=1);

namespace App\Cash\Application\Accounts;

use App\Cash\Application\DTO\MoneyAccountBankLinkInput;
use App\Cash\Entity\Accounts\MoneyAccount;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Сохраняет новый или изменённый счёт. Граница транзакции — здесь, а не в контроллере.
 */
final class SaveMoneyAccountAction
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository $balances,
        private readonly \App\Cash\Service\Accounts\AccountBalanceService $balanceService,
    ) {
    }

    public function createDraft(\App\Company\Entity\Company $company): MoneyAccount
    {
        return new MoneyAccount(\Ramsey\Uuid\Uuid::uuid7()->toString(), $company, \App\Cash\Enum\Accounts\MoneyAccountType::BANK, '', 'RUB');
    }

    public function __invoke(MoneyAccount $account, ?MoneyAccountBankLinkInput $bank = null): void
    {
        $this->entityManager->wrapInTransaction(function () use ($account, $bank): void {
            $company = $account->getCompany();
            $this->balances->acquireRecalculationLock($account);
            if (null !== $bank) {
                $this->applyBankLink($account, $bank);
            }
            $this->entityManager->persist($account);
            $this->entityManager->flush();
            $this->balanceService->recalculateDailyRange($company, $account, $account->getOpeningBalanceDate(), new \DateTimeImmutable('today'));
            $this->entityManager->refresh($account);
        });
    }

    private function applyBankLink(MoneyAccount $account, MoneyAccountBankLinkInput $bank): void
    {
        if ($bank->clear) {
            $account->clearBankLink();
            $account->setBankCursor(null);
        } else {
            $shouldLinkBank = '' !== $bank->provider && '' !== $bank->externalAccountId;

            if ($shouldLinkBank) {
                $account->setBankLink(
                    $bank->provider,
                    $bank->externalAccountId,
                    '' !== $bank->number ? $bank->number : null
                );
            }

            $bankMeta = $account->getBankMeta() ?? [];

            if ('' !== $bank->number) {
                $bankMeta['number'] = $bank->number;
            } else {
                unset($bankMeta['number']);
            }

            if ($bank->authProvided) {
                $bankMeta['auth'] = $bank->authPayload;
            } elseif (array_key_exists('auth', $bankMeta) && '' === trim($bank->auth)) {
                unset($bankMeta['auth']);
            }

            if (!empty($bankMeta)) {
                $account->setBankMeta($bankMeta);
            } else {
                $account->setBankMeta(null);
            }
        }
    }
}
