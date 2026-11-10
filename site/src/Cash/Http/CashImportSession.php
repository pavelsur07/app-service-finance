<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Domain\Identifier\CanonicalUuid;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Entity\Company;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class CashImportSession
{
    public function __construct(private MoneyAccountRepository $accounts)
    {
    }

    public function read(SessionInterface $session, Company $company, string $key = 'cash_file_import'): ?CashImportSessionState
    {
        $payload = $session->get($key);
        $accountId = is_array($payload) ? ($payload['account_id'] ?? null) : null;
        $account = null;
        if (is_array($payload) && ($payload['company_id'] ?? null) === $company->getId()
            && ('bank1c_import' !== $key || is_array($payload['preview'] ?? null))
            && is_string($accountId) && CanonicalUuid::isValid($accountId)) {
            $account = $this->accounts->findOneByIdAndCompanyId($accountId, (string) $company->getId());
        }
        if (null === $account || !is_array($payload)) {
            $session->remove($key);

            return null;
        }

        return new CashImportSessionState($payload, $account);
    }
}
