<?php

declare(strict_types=1);

namespace App\Telegram\Application;

/** Telegram pilot scope; invalid configuration denies the entire list. */
final readonly class PaymentIntakeAccess
{
    /** @var list<string> */
    private array $companyIds;

    public function __construct(private bool $telegramPaymentIntakeEnabled, string $telegramPaymentIntakeCompanies)
    {
        $ids = array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', $telegramPaymentIntakeCompanies));
        foreach ($ids as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
                $this->companyIds = [];

                return;
            }
        }
        $this->companyIds = $ids;
    }

    public function isPilotCompany(string $companyId): bool
    {
        return in_array(strtolower($companyId), $this->companyIds, true);
    }

    public function isAllowed(string $companyId): bool
    {
        return $this->telegramPaymentIntakeEnabled && $this->isPilotCompany($companyId);
    }
}
