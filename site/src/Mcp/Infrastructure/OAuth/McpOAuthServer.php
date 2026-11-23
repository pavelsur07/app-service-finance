<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use App\Mcp\Security\McpOAuthConfig;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final readonly class McpOAuthServer
{
    public function __construct(
        private McpOAuthConfig $config,
        private OAuthRepositories $repositories,
        private OAuthTokenResponse $tokenResponse,
    ) {
    }

    /** @param array<string, string> $query */
    public function validateAuthorization(array $query): AuthorizationRequestInterface
    {
        $request = (new ServerRequest('GET', $this->config->origin().'/oauth/authorize'))->withQueryParams($query);

        return $this->server()->validateAuthorizationRequest($request);
    }

    public function completeAuthorization(AuthorizationRequestInterface $authorization): HttpResponse
    {
        try {
            $response = $this->server()->completeAuthorizationRequest($authorization, new Response());
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse(new Response());
        }

        $callback = $response->getHeaderLine('Location');
        if ('' !== $callback) {
            $response = $response->withHeader('Location', (string) Uri::withQueryValue(new Uri($callback), 'iss', $this->config->origin()));
        }

        return (new HttpFoundationFactory())->createResponse($response);
    }

    /** @param array<string, string> $body */
    public function issueToken(#[\SensitiveParameter] array $body): HttpResponse
    {
        $request = (new ServerRequest('POST', $this->config->origin().'/oauth/token'))->withParsedBody($body);

        return (new HttpFoundationFactory())->createResponse(
            $this->server()->respondToAccessTokenRequest($request, new Response()),
        );
    }

    public function verifyBearer(#[\SensitiveParameter] string $authorization): void
    {
        $request = new ServerRequest('POST', $this->config->resource(), ['Authorization' => $authorization]);
        (new ResourceServer($this->repositories, $this->config->verificationKey()))->validateAuthenticatedRequest($request);
    }

    private function server(): AuthorizationServer
    {
        $server = new AuthorizationServer($this->repositories, $this->repositories, $this->repositories, $this->config->signingKey(), $this->config->encryptionKey(), $this->tokenResponse);
        $server->setDefaultScope(McpOAuthConfig::DEFAULT_SCOPE);
        $code = new AuthCodeGrant($this->repositories, $this->repositories, new \DateInterval('PT60S'));
        $refresh = new RefreshTokenGrant($this->repositories);
        $idle = new \DateInterval('P7D');
        $code->setRefreshTokenTTL($idle);
        $refresh->setRefreshTokenTTL($idle);
        $access = new \DateInterval('PT10M');
        $server->enableGrantType($code, $access);
        $server->enableGrantType($refresh, $access);

        return $server;
    }
}
