<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Domain\OAuth\AuthorizationRequest;
use App\BankSync\Domain\OAuth\PendingAuthorization;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\OAuth\AuthorizationSessionStore;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Repository\BankConnectionRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Начало входа клиента в банк: `state` и PKCE в сессию, наружу — URL страницы входа банка.
 * В БД ничего не пишет: подключение появляется только после успешного callback.
 */
final readonly class StartBankAuthorizationAction
{
    public function __construct(
        private BankAuthorizationProviderRegistry $providers,
        private BankConnectionRepository $connections,
        private AuthorizationSessionStore $sessionStore,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string|null $connectionId переподключение существующего; null — новое подключение
     *
     * @throws UnknownBankCodeException у банка нет входа по OAuth
     * @throws BankAuthorizationNotConfiguredException вход в этой среде банка не настроен
     * @throws BankConnectionNotFoundException переподключаемое не принадлежит компании
     */
    public function __invoke(string $companyId, BankCode $bankCode, string $baseUrl, ?string $connectionId, string $redirectUri): string
    {
        Assert::startsWith($baseUrl, 'https://');
        if (null !== $connectionId) {
            $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
                ?? throw new BankConnectionNotFoundException($connectionId);
            // Переподключение — в тот же банк и ту же среду.
            $bankCode = $connection->getBankCode();
            $baseUrl = $connection->getBaseUrl();
        }
        $provider = $this->providers->get($bankCode);

        $now = $this->clock->now();
        $pkce = Pkce::generate();
        $pending = new PendingAuthorization(
            Uuid::uuid4()->toString(),
            $companyId,
            $bankCode,
            $baseUrl,
            $connectionId ?? Uuid::uuid7()->toString(),
            null === $connectionId,
            $pkce->verifier,
            $redirectUri,
            $now->modify(sprintf('+%d seconds', PendingAuthorization::TTL_SECONDS)),
            bin2hex(random_bytes(16)),
        );
        // URL до записи в сессию: ненастроенная среда не оставляет начатого входа.
        $url = $provider->authorizationUrl(new AuthorizationRequest($baseUrl, $pending->state, $pkce->challenge, $redirectUri, $pending->nonce));
        $this->sessionStore->save($pending, $now);

        return $url;
    }
}
