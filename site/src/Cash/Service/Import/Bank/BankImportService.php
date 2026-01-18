<?php

namespace App\Cash\Service\Import\Bank;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;
use App\Cash\Service\Import\ImportLogger;
use App\Entity\Company;
use App\Enum\CashDirection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class BankImportService
{
    public function __construct(
        private readonly BankImportCursorRepository $cursorRepository,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ImportLogger $importLogger,
    ) {
    }

    public function importCompany(
        string $bankCode,
        Company $company,
        BankConnection $connection,
        BankStatementsProviderInterface $provider,
    ): void {
        $totalAccountsFound = 0;
        $matchedAccounts = 0;
        $transactionsCreated = 0;
        $transactionsSkipped = 0;

        $importLog = $this->importLogger->start($company, 'bank:'.$bankCode, false, null, null);

        try {
            $accountsResponse = $provider->getAccounts($connection);
            $accountNumbers = $this->extractAccountNumbers($accountsResponse);
            $totalAccountsFound += count($accountNumbers);

            foreach ($accountNumbers as $accountNumber) {
                $moneyAccount = $this->moneyAccountRepository->findOneByCompanyAndAccountNumber($company, $accountNumber);
                if (!$moneyAccount instanceof MoneyAccount) {
                    $this->logger->warning('Bank import skipped: account not found', [
                        'company' => $company->getId(),
                        'bank_code' => $bankCode,
                        'account_number' => $accountNumber,
                    ]);
                    continue;
                }

                ++$matchedAccounts;

                $cursor = $this->cursorRepository->getOrCreate($company, $bankCode, $accountNumber);
                $today = new \DateTimeImmutable('today');
                $start = $cursor->getLastImportedDate()
                    ? $cursor->getLastImportedDate()->modify('-1 day')
                    : $today->modify('-7 days');
                $end = $today;

                for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                    $page = 1;
                    while (true) {
                        $response = $provider->getTransactions($connection, $accountNumber, $date, $page);
                        $transactions = $this->extractTransactions($response);
                        if ([] === $transactions) {
                            break;
                        }

                        foreach ($transactions as $transaction) {
                            if (!is_array($transaction)) {
                                continue;
                            }

                            $externalId = $transaction['uuid'] ?? null;
                            if (!is_string($externalId) || '' === $externalId) {
                                $this->logger->warning('Bank import skipped transaction without uuid', [
                                    'company' => $company->getId(),
                                    'bank_code' => $bankCode,
                                    'account_number' => $accountNumber,
                                    'transaction' => $transaction,
                                ]);
                                continue;
                            }

                            if ($this->cashTransactionRepository->findOneByImport($company->getId(), $bankCode, $externalId)) {
                                ++$transactionsSkipped;
                                continue;
                            }

                            $occurredAt = $this->resolveOccurredAt($transaction);
                            if (!$occurredAt instanceof \DateTimeImmutable) {
                                $this->logger->warning('Bank import skipped transaction without date', [
                                    'company' => $company->getId(),
                                    'bank_code' => $bankCode,
                                    'account_number' => $accountNumber,
                                    'external_id' => $externalId,
                                ]);
                                continue;
                            }

                            $amount = $this->resolveAmount($transaction);
                            if (null === $amount) {
                                $this->logger->warning('Bank import skipped transaction without amount', [
                                    'company' => $company->getId(),
                                    'bank_code' => $bankCode,
                                    'account_number' => $accountNumber,
                                    'external_id' => $externalId,
                                ]);
                                continue;
                            }

                            $currency = $this->resolveCurrency($transaction, $moneyAccount->getCurrency());
                            $direction = $this->resolveDirection($transaction);
                            if (!$direction instanceof CashDirection) {
                                continue;
                            }
                            $description = $this->resolveDescription($transaction);

                            $cashTransaction = new CashTransaction(
                                Uuid::uuid4()->toString(),
                                $company,
                                $moneyAccount,
                                $direction,
                                $amount,
                                $currency,
                                $occurredAt,
                            );
                            $cashTransaction
                                ->setExternalId($externalId)
                                ->setImportSource($bankCode)
                                ->setDescription($description);

                            $this->entityManager->persist($cashTransaction);
                            ++$transactionsCreated;
                        }

                        if (!$this->hasNextPage($response)) {
                            break;
                        }

                        ++$page;
                    }

                    $this->entityManager->flush();
                    $cursor->setLastImportedDate(new \DateTimeImmutable($date->format('Y-m-d')));
                    $this->cursorRepository->save($cursor);
                }
            }

            $this->finishImportLog($importLog, [
                'bank' => $bankCode,
                'accounts_found' => $totalAccountsFound,
                'accounts_matched' => $matchedAccounts,
                'transactions_created' => $transactionsCreated,
                'transactions_skipped_duplicates' => $transactionsSkipped,
            ], $transactionsCreated, $transactionsSkipped, 0);
        } catch (\Throwable $exception) {
            $this->finishImportLog($importLog, [
                'error' => $exception->getMessage(),
            ], $transactionsCreated, $transactionsSkipped, 1);

            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    private function extractAccountNumbers(array $response): array
    {
        $accounts = $response['Data']['Account'] ?? [];
        if (!is_array($accounts)) {
            return [];
        }

        $accountNumbers = [];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }

            $details = $account['AccountDetails'] ?? [];
            if (!is_array($details)) {
                continue;
            }

            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }

                $identification = $detail['identification'] ?? null;
                if (is_string($identification) && '' !== $identification) {
                    $accountNumbers[] = $identification;
                }
            }
        }

        return array_values(array_unique($accountNumbers));
    }

    /**
     * @return list<array>
     */
    private function extractTransactions(array $response): array
    {
        if (!isset($response['transactions']) || !is_array($response['transactions'])) {
            return [];
        }

        return array_values(array_filter($response['transactions'], 'is_array'));
    }

    private function hasNextPage(array $response): bool
    {
        $links = $response['_links'] ?? null;
        if (!is_array($links)) {
            return false;
        }

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $rel = $link['rel'] ?? null;
            if ('next' === $rel) {
                return true;
            }
        }

        return false;
    }

    private function resolveOccurredAt(array $transaction): ?\DateTimeImmutable
    {
        $operationDate = $transaction['operationDate'] ?? null;
        if (is_string($operationDate) && '' !== $operationDate) {
            try {
                return new \DateTimeImmutable($operationDate);
            } catch (\Throwable) {
                return null;
            }
        }

        $documentDate = $transaction['documentDate'] ?? null;
        if (!is_string($documentDate) || '' === $documentDate) {
            return null;
        }

        try {
            return new \DateTimeImmutable($documentDate.' 00:00:00');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAmount(array $transaction): ?string
    {
        $amountValue = $transaction['amount'] ?? null;
        if (!is_array($amountValue)) {
            return null;
        }

        $rawAmount = $amountValue['amount'] ?? null;
        if (!is_numeric($rawAmount)) {
            return null;
        }

        return number_format((float) $rawAmount, 2, '.', '');
    }

    private function resolveCurrency(array $transaction, string $fallback): string
    {
        $currency = null;
        if (is_array($transaction['amount'] ?? null)) {
            $currency = $transaction['amount']['currencyName'] ?? null;
        }

        if (is_string($currency) && '' !== $currency) {
            return strtoupper($currency);
        }

        return strtoupper($fallback);
    }

    private function resolveDirection(array $transaction): ?CashDirection
    {
        $directionValue = $transaction['direction'] ?? null;
        if (!is_string($directionValue)) {
            return null;
        }

        if ('DEBIT' === $directionValue) {
            return CashDirection::OUTFLOW;
        }

        if ('CREDIT' === $directionValue) {
            return CashDirection::INFLOW;
        }

        return null;
    }

    private function resolveDescription(array $transaction): ?string
    {
        $description = $transaction['paymentPurpose'] ?? null;
        if (!is_string($description)) {
            return null;
        }

        $description = trim($description);

        return '' !== $description ? $description : null;
    }

    private function finishImportLog(
        ImportLog $importLog,
        array $payload,
        int $createdCount,
        int $skippedDuplicates,
        int $errorsCount,
    ): void {
        $encodedPayload = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (false === $encodedPayload) {
            $encodedPayload = '{"error":"Failed to encode import payload"}';
        }

        $importLog->setCreatedCount($createdCount);
        $importLog->setSkippedDuplicates($skippedDuplicates);
        $importLog->setErrorsCount($errorsCount);
        $importLog->setFileName($encodedPayload);
        $this->importLogger->finish($importLog);
    }
}
