<?php

declare(strict_types=1);

namespace App\Billing\Facade;

use App\Billing\Domain\SubscriptionAccessPolicy;
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
        $subscription = $this->subscriptions->findOneByCompanyId($companyId);
        $tariff = $subscription?->getTariff();

        return new BillingAccessDTO(
            $this->policy->statusOn($subscription, $now),
            $tariff?->getCode(),
            $tariff?->getName(),
            $subscription?->getPaidUntil(),
            $this->policy->daysLeft($subscription, $now),
        );
    }
}
