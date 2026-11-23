<?php

declare(strict_types=1);

namespace App\Mcp\Application;

use App\Company\Facade\CompanyFacade;
use App\Mcp\Infrastructure\OAuth\McpOAuthServer;
use App\Mcp\Infrastructure\OAuth\McpOAuthStore;
use App\Mcp\Infrastructure\OAuth\OAuthConnection;
use App\Mcp\Infrastructure\OAuth\OAuthConnectionSubject;
use App\Mcp\Security\McpEntitlements;
use App\Mcp\Security\McpOAuthConfig;
use Defuse\Crypto\Crypto;
use Doctrine\DBAL\Connection;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class McpConsentAction
{
    public function __construct(
        private Connection $db,
        private McpOAuthConfig $config,
        private McpOAuthServer $oauth,
        private McpOAuthStore $store,
        private McpEntitlements $entitlements,
        private CompanyFacade $companies,
    ) {
    }

    /** @param array<string, string> $query */
    public function begin(array $query): string
    {
        if ('code' !== ($query['response_type'] ?? null) || 'S256' !== ($query['code_challenge_method'] ?? null)
            || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $query['code_challenge'] ?? '')) {
            throw OAuthServerException::invalidRequest('code_challenge');
        }
        $redirect = $query['redirect_uri'] ?? '';
        if (!\in_array($redirect, $this->config->redirectsFor($query['client_id'] ?? ''), true)) {
            throw OAuthServerException::invalidRequest('redirect_uri');
        }
        if (($query['resource'] ?? $this->config->resource()) !== $this->config->resource()) {
            throw new OAuthServerException('The requested resource is invalid.', 0, 'invalid_target', 400);
        }

        $authorization = $this->oauth->validateAuthorization($query);
        if ([] === $authorization->getScopes()) {
            throw OAuthServerException::invalidScope('');
        }

        $id = Uuid::uuid4()->toString();
        $payload = Crypto::encrypt(json_encode($query, \JSON_THROW_ON_ERROR), $this->config->encryptionKey());
        $this->store->saveRequest($id, $payload);

        return $id;
    }

    /** @return array{clientId: string, scopes: list<string>, companies: list<array{id: string, name: string}>} */
    public function view(string $id, string $actorId): array
    {
        $authorization = $this->restore($this->store->readRequest($id));
        $scopes = $this->scopeIds($authorization);

        return [
            'clientId' => $authorization->getClient()->getName(),
            'scopes' => $scopes,
            'companies' => array_values(array_filter(
                $this->companies->listAccessibleCompaniesForUser($actorId),
                fn (array $company): bool => $scopes === $this->entitlements->allowedScopes($company['id'], $actorId, $scopes),
            )),
        ];
    }

    public function finish(string $id, string $actorId, ?string $companyId): \Symfony\Component\HttpFoundation\Response
    {
        return $this->db->transactional(function () use ($id, $actorId, $companyId): \Symfony\Component\HttpFoundation\Response {
            $authorization = $this->restore($this->store->consumeRequest($id));
            $scopes = $this->scopeIds($authorization);
            $subjectId = Uuid::uuid7()->toString();
            if (null !== $companyId) {
                if ($scopes !== $this->entitlements->allowedScopes($companyId, $actorId, $scopes, lock: true)) {
                    throw new AccessDeniedHttpException('Подключение недоступно для выбранной компании.');
                }

                $this->store->saveConnection(new OAuthConnection(
                    $subjectId,
                    $companyId,
                    $actorId,
                    $authorization->getClient()->getIdentifier(),
                    $this->config->resource(),
                    $scopes,
                    new \DateTimeImmutable('+30 days'),
                ));
            }
            $authorization->setUser(new OAuthConnectionSubject($subjectId));
            $authorization->setAuthorizationApproved(null !== $companyId);

            return $this->oauth->completeAuthorization($authorization);
        });
    }

    private function restore(?string $payload): AuthorizationRequestInterface
    {
        if (null === $payload) {
            throw new NotFoundHttpException('Запрос подключения истёк или уже обработан.');
        }
        /** @var array<string, string> $query */
        $query = json_decode(Crypto::decrypt($payload, $this->config->encryptionKey()), true, 512, \JSON_THROW_ON_ERROR);
        if (($query['resource'] ?? $this->config->resource()) !== $this->config->resource()) {
            throw new NotFoundHttpException();
        }

        try {
            return $this->oauth->validateAuthorization($query);
        } catch (OAuthServerException) {
            throw new NotFoundHttpException('Запрос подключения больше недействителен.');
        }
    }

    /** @return list<string> */
    private function scopeIds(AuthorizationRequestInterface $authorization): array
    {
        return array_values(array_unique(array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $authorization->getScopes())));
    }
}
