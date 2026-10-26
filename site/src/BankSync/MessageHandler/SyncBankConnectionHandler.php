<?php

declare(strict_types=1);

namespace App\BankSync\MessageHandler;

use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Application\OAuthTokenRefresher;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
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
 * 0. OAuth: access истекает — обновление под блокировкой токенов подключения (сессионная,
 *    HTTP вне транзакции), новые токены — commit сразу; отказ банка → `needs_reauth`,
 *    одно warning, сообщение завершается без ретрая;
 * 1. журнал импорта — свой commit, чтобы загрузку было видно, пока она идёт;
 * 2. счета банка (HTTP) → денежный счёт по номеру → сессионная блокировка импорта счёта;
 * 3. каждый день от курсора: все страницы (HTTP, вне транзакции) → одна транзакция со
 *    строками дня, пересчётом остатков и курсором;
 * 4. итог в журнал — свой commit; при сбое журнал закрывается с ошибкой, если EM открыт.
 *
 * 401 API данных: статический токен → `needs_reauth`; OAuth → принудительное обновление
 * и ретрай с новым токеном, отказ обновления → `needs_reauth`.
 *
 * Номер счёта и строки выписки в warning/error не пишутся.
 */
#[AsMessageHandler]
final readonly class SyncBankConnectionHandler
{
    /** Глубина первой загрузки счёта, дней. */
    public const FIRST_RUN_DAYS = 7;

    /** Сколько ждать блокировку токенов подключения, секунд. */
    private const TOKEN_LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private BankConnectionRepository $connections,
        private BankImportCursorRepository $cursors,
        private BankProviderRegistry $providers,
        private BankCredentialsCodec $credentialsCodec,
        private OAuthTokenRefresher $tokenRefresher,
        private BankConnectionTokenLock $tokenLock,
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
        if (!$connection->canSync()) {
            $this->logger->info('Bank sync skipped: connection is not active', $context + ['status' => $connection->getStatus()->value]);

            return;
        }
        $context['bank_code'] = $connection->getBankCode()->value;

        try {
            $provider = $this->providers->get($connection->getBankCode());
        } catch (UnknownBankCodeException) {
            throw $this->unrecoverable('Bank sync: no provider for the bank', $context);
        }
        try {
            $access = $this->freshAccess($connection, $context);
        } catch (BankAuthorizationRejectedException $exception) {
            $this->needsReauth($connection, 'refresh_rejected', $exception, $context);

            return;
        }
        if (null === $access) {
            $this->logger->info('Bank sync skipped: connection stopped being active while tokens were refreshed', $context);

            return;
        }

        $counters = new SyncCounters();
        $importLogId = $this->cashFacade->startStatementImportLog($message->companyId, 'bank:'.$connection->getBankCode()->value);
        $this->entityManager->flush();

        try {
            $this->syncAccounts($connection, $provider, $access, $counters, $context);
            $this->cashFacade->finishStatementImportLog($message->companyId, $importLogId, $counters->summary());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->closeFailedLog($message->companyId, $importLogId, $counters, $exception, $context);
            if ($this->isUnauthorized($exception)) {
                $this->handleUnauthorized($connection, $access, $context);

                return;
            }

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
     * Токен для запросов к банку; OAuth-токены, истекающие скоро (или отвергнутый
     * `$rejectedAccessToken`), обновляются под блокировкой токенов подключения.
     *
     * @param array<string, string> $context
     *
     * @return BankAccess|null null — пока ждали блокировку, подключение перестало быть активным
     *
     * @throws BankAuthorizationRejectedException
     * @throws BankConnectionBusyException
     */
    private function freshAccess(BankConnection $connection, array $context, #[\SensitiveParameter] ?string $rejectedAccessToken = null): ?BankAccess
    {
        try {
            if (null === $rejectedAccessToken && !$this->tokenRefresher->needsRefresh($connection)) {
                return $this->access($connection, $context);
            }
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
            throw $this->undecryptable($connection, $exception, $context);
        }

        if (!$this->tokenLock->acquire($connection->getId(), self::TOKEN_LOCK_TIMEOUT_SECONDS)) {
            $this->logger->warning('Bank sync: connection tokens are locked by another process', $context);

            throw new BankConnectionBusyException($connection->getId());
        }

        try {
            // Другой процесс мог обновить токены или статус, пока ждали блокировку.
            $this->entityManager->refresh($connection);
            if (!$connection->canSync()) {
                return null;
            }

            try {
                $refreshed = $this->tokenRefresher->refreshIfNeeded($connection, $rejectedAccessToken);
            } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
                throw $this->undecryptable($connection, $exception, $context);
            } catch (UnknownBankCodeException) {
                throw $this->unrecoverable('Bank sync: OAuth credentials without authorization provider', $context);
            }

            if ($refreshed) {
                try {
                    $this->entityManager->flush();
                } catch (\Throwable $exception) {
                    // Банк уже выдал новый refresh и мог погасить прежний: подключению, скорее
                    // всего, понадобится повторный вход.
                    $this->logger->error('Bank sync: refreshed OAuth tokens were not saved', $context + ['exception_class' => $exception::class]);

                    throw $exception;
                }
                $this->logger->info('Bank sync: OAuth tokens refreshed', $context);
            }
        } finally {
            $this->releaseTokenLock($connection->getId(), $context);
        }

        return $this->access($connection, $context);
    }

    /**
     * @param array<string, string> $context
     */
    private function access(BankConnection $connection, array $context): BankAccess
    {
        try {
            $token = $this->credentialsCodec->token($connection);
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
            throw $this->undecryptable($connection, $exception, $context);
        }

        return new BankAccess($connection->getId(), $connection->getBankCode(), $connection->getBaseUrl(), $token);
    }

    /**
     * @param array<string, string> $context
     */
    private function undecryptable(BankConnection $connection, \Throwable $exception, array $context): UnrecoverableMessageHandlingException
    {
        return $this->unrecoverable('Bank sync: connection credentials cannot be decrypted', $context + [
            'exception_class' => $exception::class,
            'credentials_key_version' => $connection->getCredentialsKeyVersion(),
        ]);
    }

    private function isUnauthorized(\Throwable $exception): bool
    {
        return $exception instanceof BankApiRequestFailedException && 401 === $exception->getStatusCode();
    }

    /**
     * Банк ответил 401 на запрос данных. Статический токен не обновить — нужен новый.
     * OAuth: access могли отозвать раньше срока — обновление; удалось — исключение для
     * ретрая транспорта с новым токеном.
     *
     * @param array<string, string> $context
     */
    private function handleUnauthorized(BankConnection $connection, BankAccess $access, array $context): void
    {
        try {
            $isOAuth = null !== $this->credentialsCodec->oauthTokens($connection);
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
            throw $this->undecryptable($connection, $exception, $context);
        }
        if (!$isOAuth) {
            $this->needsReauth($connection, 'token_rejected', null, $context);

            return;
        }

        try {
            $fresh = $this->freshAccess($connection, $context, $access->token);
        } catch (BankAuthorizationRejectedException $exception) {
            $this->needsReauth($connection, 'refresh_rejected', $exception, $context);

            return;
        }
        if (null === $fresh) {
            return;
        }

        $this->logger->warning('Bank sync: access token rejected, retrying with refreshed token', $context);

        throw new BankApiUnavailableException($connection->getBankCode()->value, 401);
    }

    /**
     * Банк больше не принимает учётные данные подключения: загрузка останавливается до
     * нового входа. Одно warning на переход; повторные прогоны его не повторяют — в
     * очередь такое подключение не ставится.
     *
     * @param array<string, string> $context
     */
    private function needsReauth(BankConnection $connection, string $reason, ?BankAuthorizationRejectedException $exception, array $context): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->logger->error('Bank sync: connection needs reauthorization but the entity manager is closed', $context + ['reason' => $reason]);

            return;
        }

        $this->entityManager->refresh($connection);
        if (!$connection->canSync() || !$connection->markNeedsReauth($reason)) {
            return;
        }
        $this->entityManager->flush();

        $this->logger->warning('Bank sync: connection needs reauthorization', $context + [
            'reason' => $reason,
            'status_code' => $exception?->getStatusCode() ?? 'none',
            'oauth_error' => $exception?->getOauthError() ?? 'none',
        ]);
    }

    /**
     * @param array<string, string> $context
     */
    private function releaseTokenLock(string $connectionId, array $context): void
    {
        try {
            $this->tokenLock->release($connectionId);
        } catch (\Throwable $exception) {
            // Соединение потеряно — PostgreSQL снял сессионную блокировку сам.
            $this->logger->warning('Bank sync: connection token lock release failed', $context + ['exception_class' => $exception::class]);
        }
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
