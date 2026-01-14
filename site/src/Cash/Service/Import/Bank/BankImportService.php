<?php

namespace App\Cash\Service\Import\Bank;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;
use App\Entity\Company;
use App\Enum\CashDirection;
use DateTimeImmutable;
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
    ) {
    }

    public function importCompany(
        string $bankCode,
        Company $company,
        BankConnection $connection,
        BankStatementsProviderInterface $provider
    ): void {
        $accountsResponse = $provider->getAccounts($connection);
        $accountNumbers = $this->extractAccountNumbers($accountsResponse);

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

            $cursor = $this->cursorRepository->getOrCreate($company, $bankCode, $accountNumber);
            $today = new DateTimeImmutable('today');
            $start = $cursor->getLastImportedDate()
                ? $cursor->getLastImportedDate()->modify('-1 day')
                : $today->modify('-7 days');
            $end = $today;

            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                $page = 1;
                while (true) {
                    $response = $provider->getTransactions($connection, $accountNumber, $date, $page);
                    $transactions = $this->extractTransactions($response);
                    if ($transactions === []) {
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
                            continue;
                        }

                        $occurredAt = $this->resolveOccurredAt($transaction);
                        if (!$occurredAt instanceof DateTimeImmutable) {
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
                            ->setDescription($description)
                            ->setBookedAt($occurredAt)
                            ->setUpdatedAt(new DateTimeImmutable())
                            ->setRawData($transaction);

                        $this->entityManager->persist($cashTransaction);
                    }

                    ++$page;
                }

                $this->entityManager->flush();
                $cursor->setLastImportedDate(new DateTimeImmutable($date->format('Y-m-d')));
                $this->cursorRepository->save($cursor);
            }
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
        $candidates = [
            $response['Data']['Transaction'] ?? null,
            $response['Data']['Transactions'] ?? null,
            $response['transactions'] ?? null,
            $response['data']['transactions'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        if ($this->isListOfArrays($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return [];
    }

    private function resolveOccurredAt(array $transaction): ?DateTimeImmutable
    {
        $dateValue = $transaction['operationDate'] ?? $transaction['documentDate'] ?? null;
        if (!is_string($dateValue) || '' === $dateValue) {
            return null;
        }

        try {
            return new DateTimeImmutable($dateValue);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAmount(array $transaction): ?string
    {
        $amountValue = $transaction['amount'] ?? null;
        if (is_array($amountValue)) {
            $amountValue = $amountValue['amount'] ?? $amountValue['value'] ?? null;
        }

        if (null === $amountValue) {
            return null;
        }

        if (is_numeric($amountValue)) {
            $amount = (string) $amountValue;
        } elseif (is_string($amountValue)) {
            $amount = trim($amountValue);
        } else {
            return null;
        }

        $amount = str_replace(',', '.', $amount);
        $amount = ltrim($amount, '+');

        if (str_starts_with($amount, '-')) {
            $amount = ltrim($amount, '-');
        }

        return '' !== $amount ? $amount : null;
    }

    private function resolveCurrency(array $transaction, string $fallback): string
    {
        $currency = $transaction['currency'] ?? null;
        if (is_array($transaction['amount'] ?? null)) {
            $currency = $transaction['amount']['currency'] ?? $currency;
        }

        if (is_string($currency) && '' !== $currency) {
            return strtoupper($currency);
        }

        return strtoupper($fallback);
    }

    private function resolveDirection(array $transaction): CashDirection
    {
        $directionValue = $transaction['direction'] ?? $transaction['creditDebitIndicator'] ?? null;
        if (is_string($directionValue)) {
            $normalized = strtolower($directionValue);
            if (in_array($normalized, ['credit', 'inflow', 'incoming'], true)) {
                return CashDirection::INFLOW;
            }
            if (in_array($normalized, ['debit', 'outflow', 'outgoing'], true)) {
                return CashDirection::OUTFLOW;
            }
        }

        if (is_string($transaction['amount'] ?? null) && str_starts_with(trim($transaction['amount']), '-')) {
            return CashDirection::OUTFLOW;
        }

        if (is_numeric($transaction['amount'] ?? null) && (float) $transaction['amount'] < 0) {
            return CashDirection::OUTFLOW;
        }

        return CashDirection::INFLOW;
    }

    private function resolveDescription(array $transaction): ?string
    {
        $description = $transaction['description'] ?? $transaction['paymentPurpose'] ?? null;
        if (!is_string($description)) {
            return null;
        }

        $description = trim($description);
        return '' !== $description ? $description : null;
    }

    private function isListOfArrays(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return array_is_list($value);
    }
}
