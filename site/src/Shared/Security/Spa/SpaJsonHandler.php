<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class SpaJsonHandler implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return SpaProblem::response(401, 'unauthenticated', 'Войдите в приложение.');
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return SpaProblem::response(403, 'access_denied', 'Доступ запрещён.');
    }
}
