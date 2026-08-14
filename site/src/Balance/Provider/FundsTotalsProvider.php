<?php

declare(strict_types=1);

namespace App\Balance\Provider;

use App\Balance\Enum\BalanceLinkSourceType;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Shared\Domain\ValueObject\Money;

final class FundsTotalsProvider implements BalanceValueProviderInterface
{
    public function __construct(
        private readonly FundBalanceService $fundBalanceService,
    ) {
    }

    public function supports(BalanceLinkSourceType $type): bool
    {
        return BalanceLinkSourceType::MONEY_FUNDS_TOTAL === $type;
    }

    /**
     * @return array<string, string> currency => decimal string
     */
    public function getTotalsForCompanyUpToDate(string $companyId, \DateTimeImmutable $date): array
    {
        $fundTotalsMinor = $this->fundBalanceService->getTotals($companyId);

        $fundTotals = [];
        foreach ($fundTotalsMinor as $currency => $amountMinor) {
            $fundTotals[$currency] = Money::fromMinor($amountMinor, $currency)->toDecimalString();
        }

        return $fundTotals;
    }
}
