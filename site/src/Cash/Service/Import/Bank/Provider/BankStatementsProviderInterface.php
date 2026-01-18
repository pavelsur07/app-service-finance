<?php

namespace App\Cash\Service\Import\Bank\Provider;

use App\Cash\Entity\Bank\BankConnection;

interface BankStatementsProviderInterface
{
    public function getAccounts(BankConnection $connection): array;

    public function getTransactions(
        BankConnection $connection,
        string $accountNumber,
        \DateTimeInterface $date,
        int $page,
    ): array;
}
