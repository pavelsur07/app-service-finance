<?php

declare(strict_types=1);

namespace App\Tests\Support\BankSync;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Application\DTO\BankStatementLineDTO;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Enum\BankCode;

/**
 * Банк для теста: выписка по дням, сбои по дням или на списке счетов.
 */
final class FakeBankProvider implements BankProviderInterface
{
    /** @var array<string, list<BankStatementLineDTO>> */
    public array $days = [];
    /** @var array<string, \Throwable> */
    public array $failures = [];
    public ?\Throwable $accountsFailure = null;
    /** @var list<string> */
    public array $fetchedDays = [];
    public bool $accountsListed = false;
    public ?string $lastToken = null;

    /**
     * @param list<BankAccountDTO> $accounts
     */
    public function __construct(private readonly array $accounts)
    {
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function listAccounts(BankAccess $access): array
    {
        $this->accountsListed = true;
        $this->lastToken = $access->token;
        if (null !== $this->accountsFailure) {
            throw $this->accountsFailure;
        }

        return $this->accounts;
    }

    public function fetchStatementDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date): BankStatementDayDTO
    {
        $key = $date->format('Y-m-d');
        $this->fetchedDays[] = $key;
        if (isset($this->failures[$key])) {
            throw $this->failures[$key];
        }

        return new BankStatementDayDTO($date, $this->days[$key] ?? []);
    }
}
