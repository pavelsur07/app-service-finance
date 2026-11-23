<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use App\Billing\Enum\AccessStatus;
use App\Billing\Facade\BillingFacadeInterface;
use App\Company\Facade\CompanyFacade;
use Symfony\Component\Clock\ClockInterface;

final readonly class McpEntitlements
{
    public function __construct(
        private CompanyFacade $companies,
        private BillingFacadeInterface $billing,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<string>
     */
    public function allowedScopes(string $companyId, string $userId, array $scopes, bool $lock = false): array
    {
        $financial = $this->companies->financialAccess($companyId, $userId, $lock);
        if (!$financial['owner'] || !$financial['read']) {
            return [];
        }

        $status = $this->billing->accessFor($companyId, $this->clock->now())->status;
        if (AccessStatus::NONE === $status) {
            return [];
        }

        return array_values(array_filter($scopes, static function (string $scope) use ($status, $financial): bool {
            $known = McpScope::tryFrom($scope);

            return null !== $known && (McpScope::CashDraftsCreate !== $known
                || ($financial['write'] && AccessStatus::READ_ONLY !== $status));
        }));
    }
}
