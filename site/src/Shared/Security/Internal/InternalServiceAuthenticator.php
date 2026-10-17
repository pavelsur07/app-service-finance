<?php

declare(strict_types=1);

namespace App\Shared\Security\Internal;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Firewall `internal_api`: каждый запрос к `/internal/*` проходит
 * {@see InternalAccessPolicy}. Токен не логируется ни при успехе, ни при отказе.
 */
final class InternalServiceAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly InternalAccessPolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/internal/');
    }

    public function authenticate(Request $request): Passport
    {
        $decision = $this->policy->decide($request->headers->get('Authorization'), $request->getClientIp());

        if (InternalAccessDecision::GRANTED !== $decision) {
            throw new InternalAccessDeniedException($decision);
        }

        return new SelfValidatingPassport(new UserBadge('internal-service', static fn (): InternalServicePrincipal => new InternalServicePrincipal()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $decision = $exception instanceof InternalAccessDeniedException ? $exception->decision : InternalAccessDecision::AUTH_REQUIRED;

        $this->logger->warning('Internal API access denied', [
            'reason' => $decision->value,
            'path' => $request->getPathInfo(),
            'client_ip' => $request->getClientIp(),
        ]);

        return self::errorResponse($decision);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::errorResponse(InternalAccessDecision::AUTH_REQUIRED);
    }

    private static function errorResponse(InternalAccessDecision $decision): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];
        if (InternalAccessDecision::AUTH_REQUIRED === $decision) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        return new JsonResponse(
            ['error' => ['code' => $decision->value, 'message' => $decision->message()]],
            $decision->httpStatus(),
            $headers,
        );
    }
}
