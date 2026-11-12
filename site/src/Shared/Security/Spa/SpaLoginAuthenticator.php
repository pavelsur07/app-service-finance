<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use App\Shared\Api\Request\SpaLoginRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SpaLoginAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    public function __construct(private readonly SpaContext $context, private readonly SerializerInterface $serializer, private readonly ValidatorInterface $validator)
    {
    }

    public function supports(Request $request): bool
    {
        $realm = $this->context->realm($request);

        return null !== $realm && $request->isMethod('POST') && '/api/'.$realm.'/v1/auth/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException();
        }
        try {
            $input = $this->serializer->deserialize($request->getContent(), SpaLoginRequest::class, 'json');
        } catch (NotEncodableValueException $error) {
            throw new BadRequestHttpException(previous: $error);
        } catch (NotNormalizableValueException $error) {
            throw new UnprocessableEntityHttpException(previous: $error);
        }
        if (\count($this->validator->validate($input)) > 0) {
            throw new UnprocessableEntityHttpException();
        }

        return new Passport(new UserBadge(mb_strtolower(trim($input->email))), new PasswordCredentials($input->password), [new CsrfTokenBadge($this->context->tokenId($request, 'login'), $request->headers->get('X-CSRF-Token', ''))]);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Selection belongs to the freshly authenticated user, never a previous login.
        $request->getSession()->remove('active_company_id');

        return new Response(null, 204);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof InvalidCsrfTokenException) {
            return SpaProblem::response(403, 'csrf_invalid', 'Обновите страницу и повторите действие.');
        }
        if ($exception instanceof CustomUserMessageAccountStatusException) {
            return SpaProblem::response(403, 'access_denied', 'Доступ запрещён.');
        }
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $response = SpaProblem::response(429, 'too_many_attempts', 'Слишком много попыток. Повторите позже.');
            $response->headers->set('Retry-After', (string) (max(1, (int) ($exception->getMessageData()['%minutes%'] ?? 15)) * 60));

            return $response;
        }

        return SpaProblem::response(401, 'invalid_credentials', 'Неверный email или пароль.');
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
