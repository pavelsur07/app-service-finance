<?php

declare(strict_types=1);

namespace App\Billing\Facade;

use App\Billing\Domain\SubscriptionAccessPolicy;
use App\Billing\Entity\CompanySubscription;
use App\Billing\Facade\DTO\BillingAccessDTO;
use App\Billing\Repository\CompanySubscriptionRepository;

final class BillingFacade implements BillingFacadeInterface
{
    public function __construct(
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly SubscriptionAccessPolicy $policy,
    ) {
    }

    public function accessFor(string $companyId, \DateTimeImmutable $now): BillingAccessDTO
    {
        return $this->toDto($this->subscriptions->findOneByCompanyId($companyId), $now);
    }

    public function accessForCompanies(array $companyIds, \DateTimeImmutable $now): array
    {
        $subscriptions = $this->subscriptions->findByCompanyIds($companyIds);

        $result = [];
        foreach ($companyIds as $companyId) {
            $result[$companyId] = $this->toDto($subscriptions[$companyId] ?? null, $now);
        }

        return $result;
    }

    private function toDto(?CompanySubscription $subscription, \DateTimeImmutable $now): BillingAccessDTO
    {
        $tariff = $subscription?->getTariff();

        return new BillingAccessDTO(
            $this->policy->statusOn($subscription, $now),
            $tariff?->getCode(),
            $tariff?->getName(),
            $subscription?->getPaidUntil(),
            $this->policy->daysLeft($subscription, $now),
            $subscription?->isTrial() ?? false,
        );
    }
}
