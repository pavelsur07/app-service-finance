<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Domain\OAuth\PendingAuthorization;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationDeniedException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankAuthorizationStateInvalidException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Infrastructure\OAuth\AuthorizationSessionStore;
use App\BankSync\Infrastructure\OAuth\AuthorizationStateClaims;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Callback входа в банк. `state` забирается из сессии сразу (повтор — отказ), затем под
 * сессионной блокировкой токенов подключения: обмен кода (HTTP, вне транзакции; код у
 * Альфы живёт 2 минуты) → транзакция: новое подключение с ID из `state` или
 * `reauthorize()` существующего — второго подключения повторный вход не создаёт.
 *
 * Блокировка держится от обмена до commit: два параллельных повторных входа получают пары
 * токенов по очереди, и последней сохраняется последняя выданная (банк гасит прежнюю).
 */
final readonly class CompleteBankAuthorizationAction
{
    public const TOKEN_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private AuthorizationSessionStore $sessionStore,
        private AuthorizationStateClaims $stateClaims,
        private BankAuthorizationProviderRegistry $providers,
        private BankConnectionRepository $connections,
        private BankCredentialsCodec $credentialsCodec,
        private BankConnectionTokenLock $tokenLock,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string ID подключения
     *
     * @throws BankAuthorizationStateInvalidException
     * @throws BankAuthorizationDeniedException
     * @throws BankAuthorizationRejectedException код или verifier отвергнут банком
     * @throws BankApiUnavailableException
     * @throws BankApiRequestFailedException
     * @throws BankConnectionNotFoundException переподключаемое удалили, пока клиент был в банке
     * @throws BankConnectionBusyException токены подключения меняет другой процесс
     */
    public function __invoke(string $activeCompanyId, string $state, #[\SensitiveParameter] ?string $code, ?string $error): string
    {
        $pending = '' !== $state ? $this->sessionStore->take($state) : null;
        if (null === $pending) {
            throw new BankAuthorizationStateInvalidException('unknown');
        }
        if ($pending->isExpired($this->clock->now())) {
            throw new BankAuthorizationStateInvalidException('expired');
        }
        if (!$this->stateClaims->claim($pending->state)) {
            // Параллельный callback с тем же state уже обменивает код.
            throw new BankAuthorizationStateInvalidException('unknown');
        }
        $context = [
            'company_id' => $pending->companyId,
            'bank_code' => $pending->bankCode->value,
            'bank_connection_id' => $pending->connectionId,
        ];
        if ($pending->companyId !== $activeCompanyId) {
            $this->logger->warning('Bank authorization callback for another company', $context + ['active_company_id' => $activeCompanyId]);

            throw new BankAuthorizationStateInvalidException('company_mismatch');
        }
        if (null !== $error || null === $code || '' === $code) {
            $denied = new BankAuthorizationDeniedException($error);
            $this->logger->info('Bank authorization denied', $context + ['oauth_error' => $denied->error ?? 'none']);

            throw $denied;
        }

        if (!$this->tokenLock->acquire($pending->connectionId, self::TOKEN_LOCK_TIMEOUT_SECONDS)) {
            $this->logger->warning('Bank authorization: connection tokens are locked by another process', $context);

            throw new BankConnectionBusyException($pending->connectionId);
        }
        try {
            $this->exchangeAndSave($pending, $code, $context);
        } finally {
            $this->tokenLock->release($pending->connectionId);
        }

        $this->logger->info('Bank authorization completed', $context + ['is_new_connection' => $pending->isNewConnection]);

        return $pending->connectionId;
    }

    /**
     * @param array<string, string> $context
     */
    private function exchangeAndSave(PendingAuthorization $pending, #[\SensitiveParameter] string $code, array $context): void
    {
        // До обмена: код одноразовый, и пару, которую некуда сохранить, у банка лучше не брать.
        $existing = $this->connections->findOneByIdAndCompanyId($pending->connectionId, $pending->companyId);
        if (null !== $existing) {
            $this->entityManager->refresh($existing);
            if ($existing->getBaseUrl() !== $pending->baseUrl) {
                // Среду подключения сменили, пока клиент был в банке: токены песочницы не
                // должны лечь в боевое подключение и наоборот.
                $this->logger->info('Bank authorization: connection environment changed during authorization', $context);

                throw new BankAuthorizationStateInvalidException('connection_changed');
            }
        } elseif (!$pending->isNewConnection) {
            throw new BankConnectionNotFoundException($pending->connectionId);
        }

        try {
            $tokens = $this->providers->get($pending->bankCode)
                ->exchangeCode($pending->baseUrl, $code, $pending->codeVerifier, $pending->redirectUri);
        } catch (BankApiRequestFailedException $exception) {
            // Не отказ клиента, а наша настройка или формат банка: вход не работает ни у кого.
            $this->logger->error('Bank authorization: token exchange failed', $context + ['status_code' => $exception->getStatusCode() ?? 'none']);

            throw $exception;
        }
        $credentials = $this->credentialsCodec->encryptOAuthTokens($tokens);

        $this->entityManager->wrapInTransaction(function () use ($pending, $credentials): void {
            // Под блокировкой строку никто не менял с проверки выше; удалить подключение
            // блокировка не мешает — тогда NotFound и откат.
            $connection = $this->connections->findOneByIdAndCompanyId($pending->connectionId, $pending->companyId);
            if (null !== $connection) {
                $connection->reauthorize($credentials->storageJson, $credentials->keyVersion);

                return;
            }
            if (!$pending->isNewConnection) {
                throw new BankConnectionNotFoundException($pending->connectionId);
            }

            $this->connections->add(new BankConnection(
                $pending->companyId,
                $pending->bankCode,
                $pending->baseUrl,
                $credentials->storageJson,
                $credentials->keyVersion,
                $pending->connectionId,
            ));
        });
    }
}
