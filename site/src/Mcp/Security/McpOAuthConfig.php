<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class McpOAuthConfig
{
    public const DEFAULT_SCOPE = 'accounts.read cash_categories.read cash_transactions.read';

    /** @var array<string, list<string>> */
    private array $registeredClients;

    public function __construct(
        #[Autowire('%env(default::MCP_OAUTH_ORIGIN)%')] private ?string $origin,
        #[Autowire('%env(default::MCP_OAUTH_PRIVATE_KEY)%')] #[\SensitiveParameter] private ?string $privateKey,
        #[Autowire('%env(default::MCP_OAUTH_PUBLIC_KEY)%')] private ?string $publicKey,
        #[Autowire('%env(default::MCP_OAUTH_ENCRYPTION_KEY)%')] #[\SensitiveParameter] private ?string $encryptionKey,
        #[Autowire('%env(default::MCP_OAUTH_CLIENTS)%')] ?string $clients,
    ) {
        $this->registeredClients = $this->parseClients($clients);
    }

    /** @return non-empty-string */
    public function origin(): string
    {
        if (null === $this->origin || '' === $this->origin || 1 !== preg_match('#^https://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$#D', $this->origin)) {
            throw new NotFoundHttpException();
        }

        return $this->origin;
    }

    /** @return non-empty-string */
    public function resource(): string
    {
        return $this->origin().'/mcp';
    }

    public function resourceMetadata(): string
    {
        return $this->origin().'/.well-known/oauth-protected-resource/mcp';
    }

    public function requireCanonicalRequest(Request $request): void
    {
        $path = explode('?', $request->getRequestUri(), 2)[0];
        if ($path !== $request->getPathInfo() || str_contains($path, '%')
            || [] === $this->registeredClients || !$this->privateKey || !$this->publicKey || !$this->encryptionKey
            || $request->getSchemeAndHttpHost() !== $this->origin()) {
            throw new NotFoundHttpException();
        }
    }

    public function signingKey(): CryptKey
    {
        return new CryptKey((string) $this->privateKey);
    }

    public function verificationKey(): CryptKey
    {
        return new CryptKey((string) $this->publicKey);
    }

    public function encryptionKey(): Key
    {
        return Key::loadFromAsciiSafeString((string) $this->encryptionKey);
    }

    /** @return list<string> */
    public function redirectsFor(string $clientId): array
    {
        return $this->registeredClients[$clientId] ?? [];
    }

    /** @return array<string, list<string>> */
    private function parseClients(?string $json): array
    {
        $entries = json_decode($json ?? '', true);
        if (!\is_array($entries)) {
            return [];
        }

        $clients = [];
        foreach ($entries as $clientId => $redirects) {
            if (!\is_string($clientId) || 1 !== preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $clientId)
                || !\is_array($redirects) || !array_is_list($redirects) || [] === $redirects) {
                return [];
            }

            foreach ($redirects as $redirect) {
                if (!\is_string($redirect) || !$this->isSupportedRedirect($redirect)) {
                    return [];
                }
            }
            $clients[$clientId] = $redirects;
        }

        return $clients;
    }

    private function isSupportedRedirect(string $redirect): bool
    {
        if (false === filter_var($redirect, \FILTER_VALIDATE_URL)) {
            return false;
        }

        return 1 === preg_match('#^http://(?:127\.0\.0\.1|localhost|\[::1\]):[1-9][0-9]{0,4}/[A-Za-z0-9/_-]+$#D', $redirect)
            || 1 === preg_match('#^https://(?:[a-z0-9-]+\.)+[a-z][a-z0-9-]*(?::[1-9][0-9]{0,4})?/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $redirect);
    }
}
