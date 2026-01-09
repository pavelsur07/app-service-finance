<?php

namespace App\Banking\Provider;

use App\Banking\Contract\BankProviderInterface;
use App\Banking\Dto\AccountDto;
use App\Banking\Dto\Cursor;
use App\Banking\Dto\TransactionDto;

final class DemoProvider implements BankProviderInterface
{
    public function getCode(): string
    {
        return 'demo';
    }

    public function fetchAccounts(array $authSecrets): array
    {
        return [
            new AccountDto(
                externalId: 'demo_acc_1',
                number: '40817810000000000001',
                currency: 'RUB',
                displayName: 'Demo Account'
            ),
        ];
    }

    public function fetchTransactions(
        array $authSecrets,
        string $accountExternalId,
        ?Cursor $cursor,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
    ): array {
        $txs = [
            new TransactionDto(
                externalId: 'demo_tx_1',
                accountExternalId: $accountExternalId,
                amountMinor: 150000, // 1500.00
                currency: 'RUB',
                direction: 'in',
                postedAt: new \DateTimeImmutable('-1 day'),
                description: 'Demo income',
                counterparty: 'ООО Ромашка'
            ),
            new TransactionDto(
                externalId: 'demo_tx_2',
                accountExternalId: $accountExternalId,
                amountMinor: 20000, // 200.00
                currency: 'RUB',
                direction: 'out',
                postedAt: new \DateTimeImmutable('-1 day'),
                description: 'Demo expense',
                counterparty: 'ИП Иванов'
            ),
        ];

        return ['transactions' => $txs, 'nextCursor' => null];
    }
}
