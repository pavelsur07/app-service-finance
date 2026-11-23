<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Mcp\Infrastructure\OAuth\McpOAuthServer;
use App\Mcp\Infrastructure\OAuth\McpOAuthStore;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class McpAccess
{
    public function __construct(
        private McpOAuthConfig $config,
        private McpOAuthServer $oauth,
        private McpOAuthStore $store,
        private McpEntitlements $entitlements,
    ) {
    }

    public function authenticate(Request $request): McpPrincipal
    {
        $this->config->requireCanonicalRequest($request);
        if ('/mcp' !== explode('?', $request->getRequestUri(), 2)[0]) {
            throw new NotFoundHttpException();
        }

        $origins = $request->headers->all('Origin');
        if ([] !== $origins && [$this->config->origin()] !== $origins) {
            throw new HttpException(403, 'Invalid Origin.');
        }

        $headers = $request->headers->all('Authorization');
        if ([] === $headers) {
            throw new UnauthorizedHttpException($this->challenge(), 'Bearer authentication required.');
        }
        $authorization = $headers[0] ?? null;
        if (1 !== \count($headers) || !\is_string($authorization) || 4096 < \strlen($authorization)
            || 1 !== preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/iD', $authorization, $matches)) {
            throw $this->invalidToken();
        }

        try {
            $token = (new Parser(new JoseEncoder()))->parse($matches[1]);
            if (!$token instanceof UnencryptedToken || 'at+jwt' !== $token->headers()->get('typ') || 'RS256' !== $token->headers()->get('alg')) {
                throw $this->invalidToken();
            }
            $claims = $token->claims();
            $scopes = $claims->get('scopes');
            if ($claims->get('iss') !== $this->config->origin() || [$this->config->resource()] !== $claims->get('aud')
                || !\is_string($claims->get('jti')) || !\is_string($claims->get('sub'))
                || !\is_array($scopes) || !array_is_list($scopes)) {
                throw $this->invalidToken();
            }
            $this->oauth->verifyBearer($authorization);
        } catch (JwtException|OAuthServerException) {
            throw $this->invalidToken();
        }

        $connection = $this->store->activeConnectionForCredential('access', $claims->get('jti'));
        if (null === $connection || $this->config->resource() !== $connection->resource || $claims->get('sub') !== $connection->id
            || $claims->get('client_id') !== $connection->clientId || [] === $this->config->redirectsFor($connection->clientId)) {
            throw $this->invalidToken();
        }

        $grantedScopes = array_values(array_intersect($connection->scopes, array_filter($scopes, 'is_string')));
        $allowed = $this->entitlements->allowedScopes($connection->companyId, $connection->userId, $grantedScopes);
        if ([] === $allowed) {
            throw new HttpException(403, 'Connection access denied.', headers: ['WWW-Authenticate' => $this->challenge('insufficient_scope')]);
        }

        return new McpPrincipal($connection->companyId, $connection->userId, $connection->id, $allowed);
    }

    public function challenge(string $error = '', ?string $scope = null): string
    {
        return 'Bearer resource_metadata="'.$this->config->resourceMetadata().'"'
            .('' !== $error ? ', error="'.$error.'"' : '')
            .(null !== $scope ? ', scope="'.$scope.'"' : '');
    }

    private function invalidToken(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException($this->challenge('invalid_token'), 'Invalid access token.');
    }
}
