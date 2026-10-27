<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Entity\BankAccount;
use App\BankSync\Repository\BankAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Каталог счетов банка в подключении по свежему ответу банка: новые счета — «не
 * привязан», известные обновляются, пропавшие помечаются и не загружаются (привязка и
 * курсор остаются). Только `persist()`: вызывается внутри `wrapInTransaction()` Handler'а.
 *
 * Две загрузки одного подключения одновременно (кнопка и cron) не должны вставить один
 * счёт дважды: транзакционная advisory-блокировка подключения держится до commit
 * вызывающего. Порядок: она берётся до блокировок импорта счетов.
 */
final readonly class BankAccountCatalog
{
    private const LOCK_NAMESPACE = 'bank_sync_account_catalog';

    public function __construct(
        private BankAccountRepository $accounts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Транзакционная блокировка счетов подключения: её же берёт удаление подключения, чтобы
     * каталог не создал счёт между чтением списка и каскадным удалением. Только внутри
     * транзакции вызывающего.
     */
    public function lockConnection(string $connectionId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:namespace), hashtext(:key))',
            ['namespace' => self::LOCK_NAMESPACE, 'key' => $connectionId],
        );
    }

    /**
     * @param list<BankAccountDTO> $bankAccounts
     *
     * @return list<BankAccount> все счета подключения после обновления
     */
    public function synchronize(string $companyId, string $connectionId, array $bankAccounts, \DateTimeImmutable $seenAt): array
    {
        $this->lockConnection($connectionId);
        // Подключение удалили, пока банк отвечал: счета не создаём — каскад их уже не уберёт.
        $exists = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM bank_sync_connection WHERE id = :id AND company_id = :companyId',
            ['id' => $connectionId, 'companyId' => $companyId],
        );
        if (false === $exists) {
            return [];
        }

        $known = [];
        foreach ($this->accounts->findByConnection($companyId, $connectionId) as $account) {
            // Под блокировкой — строки из БД, а не из identity map прошлого прогона.
            $this->entityManager->refresh($account);
            $known[$account->getAccountNumber()] = $account;
        }

        $seen = [];
        foreach ($bankAccounts as $bankAccount) {
            $account = $known[$bankAccount->accountNumber] ?? null;
            if (null === $account) {
                $account = new BankAccount($companyId, $connectionId, $bankAccount->accountNumber, $bankAccount->currency, $seenAt);
                $this->accounts->add($account);
                $known[$bankAccount->accountNumber] = $account;
            }
            $account->observeInBank(
                $bankAccount->currency,
                $bankAccount->enabled,
                $bankAccount->bic,
                $bankAccount->description,
                $bankAccount->externalId,
                $seenAt,
            );
            $seen[$bankAccount->accountNumber] = true;
        }

        foreach ($known as $number => $account) {
            if (!isset($seen[$number])) {
                $account->markMissingFromBank();
            }
        }

        return array_values($known);
    }
}
