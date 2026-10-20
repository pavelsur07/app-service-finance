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

    public function testRenamedOrArchivedAccountOfTheTypeIsNotDuplicated(): void
    {
        $company = $this->createCompany('renamed-accounts@example.com');
        $bank = MoneyAccountBuilder::aMoneyAccount()->withIndex(901)->forCompany($company)
            ->withType(MoneyAccountType::BANK)->withName('Сбер')->build();
        $bank->setIsActive(false);
        $this->em->persist($bank);
        $this->em->flush();

        self::assertSame(1, (self::getContainer()->get(EnsureDefaultMoneyAccountsAction::class))((string) $company->getId()));

        self::assertSame(['bank:Сбер', 'cash:Основная'], array_map(
            static fn (MoneyAccount $a): string => $a->getType()->value.':'.$a->getName(),
            $this->accounts($company),
        ));
    }

    public function testTakenNameSkipsAccountWithoutFailing(): void
    {
        $company = $this->createCompany('taken-name@example.com');
        $this->em->persist(MoneyAccountBuilder::aMoneyAccount()->withIndex(902)->forCompany($company)
            ->withType(MoneyAccountType::EWALLET)->withName('Основной счет')->build());
        $this->em->flush();

        self::assertSame(1, (self::getContainer()->get(EnsureDefaultMoneyAccountsAction::class))((string) $company->getId()));

        self::assertSame(['cash:Основная', 'ewallet:Основной счет'], array_map(
            static fn (MoneyAccount $a): string => $a->getType()->value.':'.$a->getName(),
            $this->accounts($company),
        ));
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
