<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final readonly class SpaRequestMatcher implements RequestMatcherInterface
{
    public function __construct(private SpaContext $context, private string $realm)
    {
    }

    public function matches(Request $request): bool
    {
        return $this->realm === $this->context->realm($request);
    }
}
