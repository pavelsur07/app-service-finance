<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Entity\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MoneyAccountCurrencyTest extends IntegrationTestCase
{
    public function testPersistedAccountCurrencyCannotBeChanged(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main account',
            'RUB',
        );

        $entityManager = $this->em;
        $entityManager->persist($user);
        $entityManager->persist($company);
        $entityManager->persist($account);
        $entityManager->flush();

        $account->setCurrency('USD');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Валюту существующего счёта нельзя изменить.');

        $entityManager->flush();
    }

    public function testUnsupportedCurrencyIsRejectedAtConstruction(): void
    {
        $company = $this->createCompany();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported fiat currency: BTC.');

        new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Fiat account',
            'BTC',
        );
    }

    public function testCryptoCurrencyRemainsOutsideFiatContract(): void
    {
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $this->createCompany(),
            MoneyAccountType::CRYPTO_WALLET,
            'Crypto account',
            ' btc ',
        );

        self::assertSame('BTC', $account->getCurrency());
    }

    private function createCompany(): Company
    {
        $user = UserBuilder::aUser()->build();

        return CompanyBuilder::aCompany()->withOwner($user)->build();
    }
}
