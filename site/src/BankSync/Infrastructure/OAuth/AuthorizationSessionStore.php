<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Domain\OAuth\PendingAuthorization;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Начатые входы в банк в сессии пользователя, по `state`. Запись забирается один раз;
 * в сессии их не больше нескольких — старые вытесняются.
 */
final readonly class AuthorizationSessionStore
{
    private const SESSION_KEY = 'bank_sync.oauth_pending';
    private const MAX_PENDING = 5;

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function save(PendingAuthorization $pending, \DateTimeImmutable $now): void
    {
        $all = array_filter(
            $this->all(),
            static fn (array $item): bool => null !== ($restored = PendingAuthorization::fromArray($item)) && !$restored->isExpired($now),
        );
        $all[$pending->state] = $pending->toArray();
        $this->requestStack->getSession()->set(self::SESSION_KEY, \array_slice($all, -self::MAX_PENDING, null, true));
    }

    public function take(string $state): ?PendingAuthorization
    {
        $all = $this->all();
        $item = $all[$state] ?? null;
        if (null === $item) {
            return null;
        }

        unset($all[$state]);
        $this->requestStack->getSession()->set(self::SESSION_KEY, $all);

        $pending = PendingAuthorization::fromArray($item);

        return null !== $pending && $pending->state === $state ? $pending : null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function all(): array
    {
        $all = $this->requestStack->getSession()->get(self::SESSION_KEY, []);

        return \is_array($all) ? array_filter($all, 'is_array') : [];
    }
}
