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
    /** @var int|null сколько запросов списка счетов падают с `$accountsFailure`; null — все */
    public ?int $accountsFailuresLeft = null;
    /** @var list<string> */
    public array $fetchedDays = [];
    public bool $accountsListed = false;
    public ?string $lastToken = null;
    /** @var (\Closure(): void)|null вызывается внутри запроса списка счетов — «пока банк отвечал» */
    public ?\Closure $duringAccountsRequest = null;
    /** @var (\Closure(string): void)|null вызывается внутри запроса дня с датой Y-m-d */
    public ?\Closure $duringDayRequest = null;

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
        if (null !== $this->duringAccountsRequest) {
            ($this->duringAccountsRequest)();
        }
        if (null !== $this->accountsFailure && (null === $this->accountsFailuresLeft || $this->accountsFailuresLeft-- > 0)) {
            throw $this->accountsFailure;
        }

        return $this->accounts;
    }

    public function fetchStatementDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date): BankStatementDayDTO
    {
        $key = $date->format('Y-m-d');
        $this->fetchedDays[] = $key;
        if (null !== $this->duringDayRequest) {
            ($this->duringDayRequest)($key);
        }
        if (isset($this->failures[$key])) {
            throw $this->failures[$key];
        }

        return new BankStatementDayDTO($date, $this->days[$key] ?? []);
    }
}
