<?php

declare(strict_types=1);

namespace App\BankSync\Domain;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Адаптер банка: запросы к API и разбор формата банка. Наружу отдаёт только DTO — формат
 * банка за пределы адаптера не выходит.
 */
#[AutoconfigureTag(self::TAG)]
interface BankProviderInterface
{
    public const TAG = 'bank_sync.provider';

    public function code(): BankCode;

    /**
     * @return list<BankAccountDTO>
     *
     * @throws BankApiUnavailableException временный сбой, повтор может помочь
     * @throws BankApiRequestFailedException постоянный отказ банка
     */
    public function listAccounts(BankAccess $access): array;

    /**
     * Выписка счёта за один день, все страницы.
     *
     * @throws BankApiUnavailableException временный сбой, повтор может помочь
     * @throws BankApiRequestFailedException постоянный отказ банка
     */
    public function fetchStatementDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date): BankStatementDayDTO;
}
