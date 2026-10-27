<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Entity\BankConnection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankAccountRepository;
use App\BankSync\Repository\BankConnectionRepository;
use App\Cash\Facade\CashFacade;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Удаление подключения. OAuth: сначала отзыв refresh-токена в банке (гасит и access), потом
 * удаление — под блокировкой токенов подключения, чтобы воркер не обновил refresh между
 * чтением и отзывом. HTTP к банку — вне транзакции.
 *
 * Отзыв не удался (сеть, отказ банка, нечитаемые учётные данные) — одно `warning`, удаление
 * всё равно проходит: клиент хочет отключиться, а токен банк погасит сам по сроку.
 *
 * Счета банка подключения удаляются каскадом FK, признак «выписка из банка по API» у
 * привязанных денежных счетов снимается в той же транзакции.
 *
 * Курсоры счетов остаются: новое подключение к тому же банку продолжит загрузку с них, а
 * не перечитает неделю.
 */
final readonly class DeleteBankConnectionAction
{
    public const TOKEN_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private BankConnectionRepository $connections,
        private BankAccountRepository $bankAccounts,
        private BankAccountCatalog $accountCatalog,
        private CashFacade $cashFacade,
        private BankCredentialsCodec $credentialsCodec,
        private BankAuthorizationProviderRegistry $authorizationProviders,
        private BankConnectionTokenLock $tokenLock,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws BankConnectionNotFoundException подключение не принадлежит компании или уже удалено
     * @throws BankConnectionBusyException токены подключения меняет другой процесс
     */
    public function __invoke(string $companyId, string $connectionId): void
    {
        $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
            ?? throw new BankConnectionNotFoundException($connectionId);
        $context = [
            'company_id' => $companyId,
            'bank_code' => $connection->getBankCode()->value,
            'bank_connection_id' => $connectionId,
        ];

        if (!$this->tokenLock->acquire($connectionId, self::TOKEN_LOCK_TIMEOUT_SECONDS)) {
            $this->logger->warning('Bank connection delete: connection tokens are locked by another process', $context);

            throw new BankConnectionBusyException($connectionId);
        }
        try {
            try {
                // Пока ждали блокировку, воркер мог обновить refresh: отзывать — последний.
                $this->entityManager->refresh($connection);
            } catch (EntityNotFoundException) {
                throw new BankConnectionNotFoundException($connectionId);
            }

            $this->revokeInBank($connection, $context);
            // Подключение, его счета банка (каскад FK) и признак источника у привязанных
            // денежных счетов — одной транзакцией: признак не переживает привязку.
            // Блокировка каталога — до чтения списка: параллельная загрузка не создаст счёт,
            // которого список не увидит, а каскад удалит вместе с привязкой.
            $this->entityManager->wrapInTransaction(function () use ($connection, $companyId, $connectionId): void {
                $this->accountCatalog->lockConnection($connectionId);
                $moneyAccountIds = [];
                foreach ($this->bankAccounts->findByConnection($companyId, $connectionId) as $account) {
                    $this->entityManager->refresh($account, LockMode::PESSIMISTIC_WRITE);
                    $moneyAccountId = $account->getMoneyAccountId();
                    if (null !== $moneyAccountId) {
                        $moneyAccountIds[] = $moneyAccountId;
                    }
                }
                // Денежные счета Cash блокирует по одному — в порядке id, как привязка.
                sort($moneyAccountIds);
                foreach ($moneyAccountIds as $moneyAccountId) {
                    $this->cashFacade->clearBankApiStatementSource($companyId, $moneyAccountId);
                }
                $this->connections->remove($connection);
            });
        } finally {
            $this->tokenLock->release($connectionId);
        }

        $this->logger->info('Bank connection deleted', $context);
    }

    /**
     * @param array<string, string> $context
     */
    private function revokeInBank(BankConnection $connection, array $context): void
    {
        try {
            $refreshToken = $this->credentialsCodec->oauthTokens($connection)?->refreshToken;
        } catch (DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException $exception) {
            $this->logger->warning('Bank connection delete: credentials are unreadable, token not revoked', $context + ['exception_class' => $exception::class]);

            return;
        }
        if (null === $refreshToken) {
            return;
        }

        try {
            $provider = $this->authorizationProviders->get($connection->getBankCode());
        } catch (UnknownBankCodeException) {
            return;
        }

        try {
            $provider->revoke($connection->getBaseUrl(), $refreshToken);
            $this->logger->info('Bank connection delete: token revoked in the bank', $context);
        } catch (BankApiUnavailableException|BankApiRequestFailedException $exception) {
            $this->logger->warning('Bank connection delete: token revocation failed, connection deleted anyway', $context + [
                'exception_class' => $exception::class,
                'status_code' => $exception->getStatusCode() ?? 'none',
            ]);
        } catch (BankAuthorizationNotConfiguredException|BankChannelNotConfiguredException $exception) {
            $this->logger->warning('Bank connection delete: bank sign-in is not configured, token not revoked', $context + [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
