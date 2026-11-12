<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class SpaCsrf
{
    public function __construct(private SpaContext $context, private CsrfTokenManagerInterface $tokens)
    {
    }

    public function validate(Request $request, string $purpose): void
    {
        if (!$this->tokens->isTokenValid(new CsrfToken($this->context->tokenId($request, $purpose), $request->headers->get('X-CSRF-Token')))) {
            throw new SpaHttpException(403, 'csrf_invalid', 'Обновите страницу и повторите действие.');
        }
    }
}
