<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\EnsureDefaultMoneyAccountsAction;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class EnsureDefaultMoneyAccountsActionTest extends IntegrationTestCase
{
    public function testCreatesBankAndCashAccountsOnceAndIsIdempotent(): void
    {
        $company = $this->createCompany('default-accounts@example.com');
        $action = self::getContainer()->get(EnsureDefaultMoneyAccountsAction::class);

        self::assertSame(2, $action((string) $company->getId()));
        self::assertSame(0, $action((string) $company->getId()));

        $accounts = $this->accounts($company);
        self::assertSame(['bank:Основной счет:RUB', 'cash:Основная:RUB'], array_map(
            static fn (MoneyAccount $a): string => sprintf('%s:%s:%s', $a->getType()->value, $a->getName(), $a->getCurrency()),
            $accounts,
        ));
        foreach ($accounts as $account) {
            self::assertTrue($account->isActive());
            self::assertSame('7', ((string) $account->getId())[14], 'ID счёта — UUID v7');
        }
    }

    /**
     * Регрессия: компания со своими счетами получала недостающий по типу счёт «по умолчанию»
     * (COMPSHOP с 8 банковскими счетами — пустую кассу «Основная»).
     */
    #[DataProvider('ownAccounts')]
    public function testCompanyWithAnyAccountGetsNothing(MoneyAccountType $type, bool $active): void
    {
        $company = $this->createCompany('own-accounts-'.$type->value.'@example.com');
        $own = MoneyAccountBuilder::aMoneyAccount()->withIndex(901)->forCompany($company)
            ->withType($type)->withName('Сбер')->build();
        $own->setIsActive($active);
        $this->em->persist($own);
        $this->em->flush();

        $action = self::getContainer()->get(EnsureDefaultMoneyAccountsAction::class);
        self::assertSame(0, $action((string) $company->getId(), dryRun: true));
        self::assertSame(0, $action((string) $company->getId()));

        self::assertSame([$type->value.':Сбер'], array_map(
            static fn (MoneyAccount $a): string => $a->getType()->value.':'.$a->getName(),
            $this->accounts($company),
        ));
    }

    /**
     * @return iterable<string, array{MoneyAccountType, bool}>
     */
    public static function ownAccounts(): iterable
    {
        yield 'только банк' => [MoneyAccountType::BANK, true];
        yield 'только касса' => [MoneyAccountType::CASH, true];
        yield 'архивный банк' => [MoneyAccountType::BANK, false];
        yield 'только кошелёк' => [MoneyAccountType::EWALLET, true];
    }

    public function testUnknownCompanyIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (self::getContainer()->get(EnsureDefaultMoneyAccountsAction::class))(Uuid::uuid7()->toString());
    }

    private function createCompany(string $email): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail($email)->withPasswordHash('p')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    /**
     * @return list<MoneyAccount>
     */
    private function accounts(Company $company): array
    {
        $this->em->clear();
        /** @var list<MoneyAccount> $accounts */
        $accounts = $this->em->getRepository(MoneyAccount::class)->findBy(['company' => (string) $company->getId()], ['type' => 'ASC']);

        return $accounts;
    }
}
