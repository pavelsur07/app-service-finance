<?php

declare(strict_types=1);

namespace App\Shared\Security\Spa;

use Symfony\Component\HttpFoundation\Request;

final readonly class SpaContext
{
    public function __construct(private string $accountHost, private string $adminHost)
    {
    }

    public function hostRealm(Request $request): ?string
    {
        return match ($request->getHost()) {
            $this->accountHost => 'account',
            $this->adminHost => 'admin',
            default => null,
        };
    }

    public function isApiPath(Request $request): bool
    {
        // Symfony's router decodes once. Detect encoded aliases before a request
        // can fall through to the legacy firewall on a different host.
        return 1 === preg_match('#^/api/(account|admin)/v1(?:/|$)#D', rawurldecode($request->getPathInfo()));
    }

    public function realm(Request $request): ?string
    {
        $realm = $this->hostRealm($request);

        return null !== $realm && str_starts_with($request->getPathInfo(), '/api/'.$realm.'/v1/') ? $realm : null;
    }

    public function tokenId(Request $request, string $purpose): string
    {
        return 'spa_'.($this->realm($request) ?? throw new \LogicException('Not a SPA API request.')).'_'.$purpose;
    }
}
