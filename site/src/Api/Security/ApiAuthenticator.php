<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Application\AuthenticateApiKeyAction;
use App\Api\Infrastructure\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class ApiAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly AuthenticateApiKeyAction $authenticateKey)
    {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/external/v1/');
    }

    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization', '');
        $token = 1 === preg_match('/^Bearer ([^\s]+)$/iD', $header, $matches) ? $matches[1] : null;
        $principal = ($this->authenticateKey)($token, $request->headers->get('X-Company-Id'), $request->getClientIp() ?? 'unknown');

        return new SelfValidatingPassport(new UserBadge($principal->keyId, static fn (): ApiPrincipal => $principal));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->start($request, $exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiProblem::response(401, 'Missing or invalid API key.');
    }
}
