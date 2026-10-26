<?php

declare(strict_types=1);

namespace App\BankSync\MessageHandler;

use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Message\SyncBankConnectionMessage;
use App\BankSync\Repository\BankConnectionRepository;
use App\BankSync\Repository\BankImportCursorRepository;
use App\Cash\Application\DTO\BankStatementDayInput;
use App\Cash\Application\DTO\BankStatementDayResult;
use App\Cash\Application\DTO\BankStatementLineInput;
use App\Cash\Application\DTO\StatementImportAccount;
use App\Cash\Facade\CashFacade;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Загрузка выписки по подключению. Точка входа владеет транзакциями:
 * 1. журнал импорта — свой commit, чтобы загрузку было видно, пока она идёт;
 * 2. счета банка (HTTP) → денежный счёт по номеру → сессионная блокировка импорта счёта;
 * 3. каждый день от курсора: все страницы (HTTP, вне транзакции) → одна транзакция со
 *    строками дня, пересчётом остатков и курсором;
 * 4. итог в журнал — свой commit; при сбое журнал закрывается с ошибкой, если EM открыт.
 *
 * Номер счёта и строки выписки в warning/error не пишутся.
 */
#[AsMessageHandler]
final readonly class SyncBankConnectionHandler
{
    /** Глубина первой загрузки счёта, дней. */
    public const FIRST_RUN_DAYS = 7;

    public function __construct(
        private BankConnectionRepository $connections,
        private BankImportCursorRepository $cursors,
        private BankProviderRegistry $providers,
        private BankCredentialsCodec $credentialsCodec,
        private CashFacade $cashFacade,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncBankConnectionMessage $message): void
    {
        $context = [
            'sync_id' => $message->syncId,
            'company_id' => $message->companyId,
            'bank_connection_id' => $message->connectionId,
        ];
        $this->logger->info('Bank sync started', $context);

        $connection = $this->connections->findOneByIdAndCompanyId($message->connectionId, $message->companyId)
            ?? throw $this->unrecoverable('Bank sync: connection not found', $context);
        if (!$connection->isActive()) {
            $this->logger->info('Bank sync skipped: connection is inactive', $context);

            return;
        }
        $context['bank_code'] = $connection->getBankCode()->value;

        try {
            $provider = $this->providers->get($connection->getBankCode());
        } catch (UnknownBankCodeException) {
            throw $this->unrecoverable('Bank sync: no provider for the bank', $context);
        }
        $access = $this->access($connection, $context);

        $counters = new SyncCounters();
        $importLogId = $this->cashFacade->startStatementImportLog($message->companyId, 'bank:'.$connection->getBankCode()->value);
        $this->entityManager->flush();

        try {
            $this->syncAccounts($connection, $provider, $access, $counters, $context);
            $this->cashFacade->finishStatementImportLog($message->companyId, $importLogId, $counters->summary());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->closeFailedLog($message->companyId, $importLogId, $counters, $exception, $context);

            throw $this->translate($exception, $context + $counters->toLogContext());
        }

        $this->logger->info('Bank sync finished', $context + $counters->toLogContext());
    }

    /**
     * @param array<string, string> $context
     */
    private function syncAccounts(
        BankConnection $connection,
        BankProviderInterface $provider,
        BankAccess $access,
        SyncCounters $counters,
        array $context,
    ): void {
        $accounts = $provider->listAccounts($access);
        $counters->accountsFound = \count($accounts);

        foreach ($accounts as $bankAccount) {
            $target = $this->cashFacade->findStatementImportAccount($connection->getCompanyId(), $bankAccount->accountNumber);
            if (null === $target) {
                // info, не warning: счёт банка без денежного счёта в сервисе — обычное дело.
                $this->logger->info('Bank sync: bank account is not linked to a money account', $context + [
                    'account_suffix' => substr($bankAccount->accountNumber, -4),
                ]);
                continue;
            }
            ++$counters->accountsMatched;

            // Импорт в счёт сериализован с файловым и 1С: занятый счёт догрузится следующим
            // прогоном с курсора.
            if (!$this->cashFacade->tryLockAccountForStatementImport($connection->getCompanyId(), $target->moneyAccountId)) {
                $this->logger->warning('Bank sync skipped: another import of the account is running', $context + [
                    'money_account_id' => $target->moneyAccountId,
                ]);
                continue;
            }

            try {
                $this->syncAccount($connection, $provider, $access, $bankAccount->accountNumber, $target, $counters, $context);
            } finally {
                $this->releaseAccountLock($connection->getCompanyId(), $target->moneyAccountId, $context);
            }
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function syncAccount(
        BankConnection $connection,
        BankProviderInterface $provider,
        BankAccess $access,
        string $accountNumber,
        StatementImportAccount $target,
        SyncCounters $counters,
        array $context,
    ): void {
        $bankCode = $connection->getBankCode();
        $cursor = $this->cursors->findOrNew($connection->getCompanyId(), $bankCode, $accountNumber);
        $today = new \DateTimeImmutable('today');

        for ($date = $cursor->nextStartDate($today, self::FIRST_RUN_DAYS); $date <= $today; $date = $date->modify('+1 day')) {
            $day = $provider->fetchStatementDay($access, $accountNumber, $date);
            if ($day->skippedLines > 0) {
                $counters->errors += $day->skippedLines;
                $this->logger->warning('Bank sync: statement lines could not be parsed', $context + [
                    'money_account_id' => $target->moneyAccountId,
                    'date' => $date->format('Y-m-d'),
                    'skipped' => $day->skippedLines,
                ]);
            }

            /** @var BankStatementDayResult $result */
            $result = $this->entityManager->wrapInTransaction(function () use ($connection, $target, $day, $cursor, $date): BankStatementDayResult {
                $result = $this->cashFacade->importBankStatementDay(
                    $connection->getCompanyId(),
                    $target->moneyAccountId,
                    $connection->getBankCode()->value,
                    $this->dayInput($day),
                );
                // Курсор — в той же транзакции, что строки дня: не обгоняет данные.
                $cursor->markImported($date);
                $this->cursors->add($cursor);

                return $result;
            });

            $counters->created += $result->created;
            $counters->skippedDuplicates += $result->skippedDuplicates;
            $counters->errors += $result->errors;
        }
    }

    private function dayInput(BankStatementDayDTO $day): BankStatementDayInput
    {
        $lines = [];
        foreach ($day->lines as $line) {
            $lines[] = new BankStatementLineInput(
                $line->externalId,
                BankDirection::CREDIT === $line->direction,
                $line->amount,
                $line->currency,
                $line->occurredAt,
                $line->description,
            );
        }

        return new BankStatementDayInput($day->date, $lines);
    }

    /**
     * @param array<string, string> $context
     */
    private function access(BankConnection $connection, array $context): BankAccess
    {
        try {
            $token = $this->credentialsCodec->token($connection);
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
            throw $this->unrecoverable('Bank sync: connection credentials cannot be decrypted', $context + ['exception_class' => $exception::class, 'credentials_key_version' => $connection->getCredentialsKeyVersion()]);
        }

        return new BankAccess($connection->getId(), $connection->getBankCode(), $connection->getBaseUrl(), $token);
    }

    /**
     * @param array<string, string> $context
     */
    private function closeFailedLog(
        string $companyId,
        string $importLogId,
        SyncCounters $counters,
        \Throwable $exception,
        array $context,
    ): void {
        // На закрытом EM (сбой внутри транзакции дня) журнал не записать; lease снимается
        // всё равно, журнал закроет ночной ремонт.
        if (!$this->entityManager->isOpen()) {
            $this->cashFacade->abandonStatementImportLog($importLogId);

            return;
        }

        try {
            $this->cashFacade->finishStatementImportLog($companyId, $importLogId, $counters->summary($exception::class));
            $this->entityManager->flush();
        } catch (\Throwable $logException) {
            $this->logger->warning('Bank sync: import log was not saved after failure', $context + [
                'exception_class' => $logException::class,
            ]);
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function releaseAccountLock(string $companyId, string $moneyAccountId, array $context): void
    {
        try {
            $this->cashFacade->releaseAccountStatementImportLock($companyId, $moneyAccountId);
        } catch (\Throwable $exception) {
            // Соединение потеряно — PostgreSQL снял сессионную блокировку сам.
            $this->logger->warning('Bank sync: account lock release failed', $context + [
                'money_account_id' => $moneyAccountId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * @param array<string, int|string> $context
     */
    private function translate(\Throwable $exception, array $context): \Throwable
    {
        if ($exception instanceof BankApiUnavailableException) {
            // Обычное исключение, а не RecoverableMessageHandlingException: Symfony ретраит
            // Recoverable бесконечно, в обход max_retries. Повторы — retry_strategy транспорта.
            $this->logger->warning('Bank sync: bank API temporarily unavailable', $context + [
                'status_code' => $exception->getStatusCode() ?? 'none',
            ]);

            return $exception;
        }

        if ($exception instanceof BankApiRequestFailedException) {
            $this->logger->error('Bank sync: bank API rejected the request', $context + [
                'status_code' => $exception->getStatusCode() ?? 'none',
            ]);

            return new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }

        return $exception;
    }

    /**
     * @param array<string, string> $context
     */
    private function unrecoverable(string $reason, array $context): UnrecoverableMessageHandlingException
    {
        $this->logger->error($reason, $context);

        return new UnrecoverableMessageHandlingException($reason);
    }
}
