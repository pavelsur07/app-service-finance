<?php

declare(strict_types=1);

namespace App\Tests\Fund\Factory;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Company\Entity\Company;
use Ramsey\Uuid\Uuid;

final class MoneyFundFactory
{
    public static function create(Company $company, string $currency = 'RUB', string $name = 'Test Fund'): MoneyFund
    {
        return new MoneyFund(Uuid::uuid4()->toString(), $company, $name, $currency);
    }
}
