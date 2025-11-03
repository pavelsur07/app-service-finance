<?php

namespace App\Banking\Contract;

use App\Banking\Dto\AccountDto;
use App\Banking\Dto\Cursor;
use App\Banking\Dto\TransactionDto;

interface BankProviderInterface
{
    /**
     * Уникальный код провайдера банка: 'alfa' | 'sber' | 'tinkoff' | 'demo' | ...
     */
    public function getCode(): string;

    /**
     * Возвращает список счетов, доступных для подключенных креденшелей.
     *
     * @param array $authSecrets Секреты/токены авторизации (MVP: из MoneyAccount.meta.bank.auth)
     *
     * @return AccountDto[]
     */
    public function fetchAccounts(array $authSecrets): array;

    /**
     * Порционная загрузка транзакций по конкретному банковскому счёту.
     *
     * @param array $authSecrets Секреты/токены авторизации
     * @param string $accountExternalId ИД счёта в банке (из AccountDto::externalId)
     * @param Cursor|null $cursor Курсор инкрементальной загрузки (sinceId/sinceDate)
     * @param \DateTimeImmutable $since Начало периода (включительно)
     * @param \DateTimeImmutable $until Конец периода (включительно/исключительно — по контракту банка)
     *
     * @return array{transactions: TransactionDto[], nextCursor: ?Cursor}
     */
    public function fetchTransactions(
        array $authSecrets,
        string $accountExternalId,
        ?Cursor $cursor,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
    ): array;
}
