<?php

declare(strict_types=1);

namespace App\Cash\Service\Import\Bank;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class BankImportService
{
    public function __construct(
        private readonly BankImportCursorRepository $cursorRepository,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ImportLogger $importLogger,
        private readonly CashTransactionResponsibilityCenterResolver $responsibilityCenterResolver,
        private readonly CashImportAccountLock $accountLock,
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
        $transactionsSkippedDuplicates = 0;
        $transactionsErrors = 0;

        $importLog = $this->importLogger->start($company, 'bank:'.$bankCode, false, null, null);

        try {
            $responsibilityPair = $this->responsibilityCenterResolver->resolveForCreate($company->getId(), null, null);
            $systemProject = $this->entityManager->getReference(
                ProjectDirection::class,
                $responsibilityPair->projectDirectionId
            );

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

                // Импорт в счёт сериализован с файловым и 1С: занятый счёт догрузится следующим
                // прогоном с курсора.
                $companyId = (string) $company->getId();
                $moneyAccountId = (string) $moneyAccount->getId();
                if (!$this->accountLock->tryAcquire($companyId, $moneyAccountId)) {
                    $this->logger->warning('Bank import skipped: another import of the account is running', [
                        'company' => $companyId,
                        'bank_code' => $bankCode,
                        'money_account' => $moneyAccountId,
                    ]);
                    continue;
                }

                try {
                    $this->importAccount(
                        $bankCode,
                        $company,
                        $connection,
                        $provider,
                        $accountNumber,
                        $moneyAccount,
                        $systemProject,
                        $responsibilityPair->responsibilityCenterId,
                        $importLog,
                        $transactionsCreated,
                        $transactionsSkippedDuplicates,
                        $transactionsErrors,
                    );
                } finally {
                    try {
                        $this->accountLock->release($companyId, $moneyAccountId);
                    } catch (\Throwable $exception) {
                        // Соединение потеряно — PostgreSQL снял сессионную блокировку сам.
                        $this->logger->warning('Bank import: account lock release failed', [
                            'company' => $companyId,
                            'exception_class' => $exception::class,
                        ]);
                    }
                }
            }

            $this->finishImportLog($importLog, [
                'bank' => $bankCode,
                'accounts_found' => $totalAccountsFound,
                'accounts_matched' => $matchedAccounts,
                'transactions_created' => $transactionsCreated,
                'transactions_skipped_duplicates' => $transactionsSkippedDuplicates,
            ], $transactionsCreated, $transactionsSkippedDuplicates, $transactionsErrors);
        } catch (\Throwable $exception) {
            $this->finishImportLog($importLog, [
                'error' => $exception->getMessage(),
            ], $transactionsCreated, $transactionsSkippedDuplicates, $transactionsErrors + 1);

            throw $exception;
        }
    }

    /**
     * Выписка одного счёта с курсора по сегодня; вызывается под блокировкой счёта. Счётчики
     * копятся в переменных вызывающего: при сбое на середине сводка учитывает записанные дни.
     */
    private function importAccount(
        string $bankCode,
        Company $company,
        BankConnection $connection,
        BankStatementsProviderInterface $provider,
        string $accountNumber,
        MoneyAccount $moneyAccount,
        ?ProjectDirection $systemProject,
        ?string $responsibilityCenterId,
        ImportLog $importLog,
        int &$transactionsCreated,
        int &$transactionsSkippedDuplicates,
        int &$transactionsErrors,
    ): void {
        /** @var array<string, CashTransaction> $pending строки дня, ещё не записанные flush() */
        $pending = [];
        $cursor = $this->cursorRepository->getOrCreate($company, $bankCode, $accountNumber);
        $today = new \DateTimeImmutable('today');
        $start = $cursor->getLastImportedDate()
            ? $cursor->getLastImportedDate()->modify('-1 day')
            : $today->modify('-7 days');
        $end = $today;

        try {
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

                        // Строка, уже записанная этим прогоном, ещё не в БД до flush() дня: тот же
                        // uuid на другой странице упал бы на уникальном индексе импорта, и день
                        // повторялся бы без конца.
                        if (isset($pending[$externalId])
                            || $this->cashTransactionRepository->findOneByImport($company->getId(), $bankCode, $externalId)
                        ) {
                            ++$transactionsSkippedDuplicates;
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
                        if (null === FiatCurrency::tryFrom($currency) || $currency !== $moneyAccount->getCurrency()) {
                            ++$transactionsErrors;
                            $this->importLogger->incError($importLog);
                            $this->logger->warning('Bank import skipped transaction with invalid currency', [
                                'company' => $company->getId(),
                                'bank_code' => $bankCode,
                                'external_id' => $externalId,
                                'expected_currency' => $moneyAccount->getCurrency(),
                                'actual_currency' => $currency,
                            ]);
                            continue;
                        }
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
                            ->setProjectDirection($systemProject)
                            ->setResponsibilityCenterId($responsibilityCenterId)
                            ->setDescription($description);

                        $this->entityManager->persist($cashTransaction);
                        $pending[$externalId] = $cashTransaction;
                        ++$transactionsCreated;
                    }

                    if (!$this->hasNextPage($response)) {
                        break;
                    }

                    ++$page;
                }

                // Транзакции дня и курсор — одним flush(): курсор не обгоняет данные.
                $cursor->setLastImportedDate(new \DateTimeImmutable($date->format('Y-m-d')));
                $this->cursorRepository->save($cursor);
                $this->entityManager->flush();
                $pending = [];
            }
        } catch (\Throwable $exception) {
            // Строки недогруженного дня не должны дожить до flush() журнала импорта после снятия
            // блокировки счёта: следующий импорт их не видит и вставил бы те же. День повторится
            // с курсора.
            foreach ($pending as $cashTransaction) {
                $this->entityManager->detach($cashTransaction);
            }
            $transactionsCreated -= \count($pending);

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
            return strtoupper(trim($currency));
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
