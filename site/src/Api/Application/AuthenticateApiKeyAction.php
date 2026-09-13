<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Domain\ApiKeySecret;
use App\Api\Repository\ApiKeyRepository;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiRateLimiter;
use App\Company\Facade\CompanyFacade;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Shared by the stateless authenticator and the owner's pasted-key check. */
final readonly class AuthenticateApiKeyAction
{
    public function __construct(
        private ApiKeyRepository $keys,
        private CompanyFacade $companies,
        private ApiRateLimiter $limits,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(#[\SensitiveParameter] ?string $token, ?string $publicCompanyId, string $ip): ApiPrincipal
    {
        $this->limits->checkFailures($ip);
        $parts = null === $token ? null : ApiKeySecret::parse($token);
        $key = null === $parts ? null : $this->keys->findOneByPublicIdentifier($parts['publicIdentifier']);
        // Also perform a hash comparison for unknown identifiers (no secret-dependent string comparisons).
        $validSecret = null === $key
            ? hash_equals(str_repeat('0', 64), hash('sha256', $parts['secret'] ?? ''))
            : $key->matchesSecret($parts['secret']);
        if (null === $key || !$validSecret || !$key->isUsableAt($this->clock->now())) {
            $this->limits->failed($ip);
            throw new UnauthorizedHttpException('Bearer', 'Missing or invalid API key.');
        }
        $this->limits->consumeKey($key->getId());
        if (null === $publicCompanyId || 1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $publicCompanyId) || (int) $publicCompanyId > 2147483647) {
            throw new UnprocessableEntityHttpException('X-Company-Id must be a positive public company ID.');
        }
        $company = $this->companies->findByPublicId((int) $publicCompanyId);
        if (null === $company || $company->getId() !== $key->getCompanyId()) {
            $this->limits->failed($ip);
            throw new AccessDeniedHttpException('API key does not belong to this company.');
        }

        return new ApiPrincipal($key->getCompanyId(), (int) $publicCompanyId, $key->getId(), $key->getExpiresAt());
    }
}
