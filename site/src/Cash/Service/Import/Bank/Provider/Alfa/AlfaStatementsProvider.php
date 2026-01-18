<?php

namespace App\Cash\Service\Import\Bank\Provider\Alfa;

use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;

class AlfaStatementsProvider implements BankStatementsProviderInterface
{
    public function __construct(private AlfaApiClient $apiClient)
    {
    }

    public function getAccounts(BankConnection $connection): array
    {
        return $this->apiClient->getAccounts($connection);
    }

    public function getTransactions(
        BankConnection $connection,
        string $accountNumber,
        \DateTimeInterface $date,
        int $page,
    ): array {
        return $this->apiClient->getTransactions($connection, $accountNumber, $date, $page);
    }
}
