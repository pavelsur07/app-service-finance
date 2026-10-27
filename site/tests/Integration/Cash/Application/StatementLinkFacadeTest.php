<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Exception\StatementImportTargetNotFoundException;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Контракт `CashFacade` для привязки счетов банка (BankSync): цель импорта по id,
 * кандидаты для привязки, признак «выписка из банка по API». Методы признака не коммитят.
 */
final class StatementLinkFacadeTest extends IntegrationTestCase
{
    private Company $company;
    private CashFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->em->flush();
        $this->facade = self::getContainer()->get(CashFacade::class);
    }

    public function testImportAccountByIdIsOnlyActiveAccountOfOwnCompany(): void
    {
        $active = $this->account($this->company, 'Расчётный', 'RUB');
        $inactive = $this->account($this->company, 'Закрытый', 'RUB');
        $inactive->setIsActive(false);
        $foreign = $this->account($this->createCompany(), 'Чужой', 'RUB');
        $this->em->flush();

        $found = $this->facade->getStatementImportAccount($this->companyId(), (string) $active->getId());
        self::assertNotNull($found);
        self::assertSame([(string) $active->getId(), 'RUB'], [$found->moneyAccountId, $found->currency]);
        self::assertNull($this->facade->getStatementImportAccount($this->companyId(), (string) $inactive->getId()));
        self::assertNull($this->facade->getStatementImportAccount($this->companyId(), (string) $foreign->getId()));
        self::assertNull($this->facade->getStatementImportAccount($this->companyId(), 'not-a-uuid'));
    }

    public function testCandidatesAreActiveAccountsOfOwnCompanyInCurrency(): void
    {
        $rub = $this->account($this->company, 'Б-расчётный', 'RUB', '4070 2810 9000 0000 0001');
        $rubNoNumber = $this->account($this->company, 'А-касса', 'RUB');
        $this->account($this->company, 'Валютный', 'USD');
        $this->account($this->company, 'Закрытый', 'RUB')->setIsActive(false);
        $this->account($this->createCompany(), 'Чужой', 'RUB');
        $this->em->flush();

        $candidates = $this->facade->listStatementLinkCandidates($this->companyId(), 'RUB');

        self::assertSame(
            [[(string) $rubNoNumber->getId(), 'А-касса', null], [(string) $rub->getId(), 'Б-расчётный', '40702810900000000001']],
            array_map(static fn ($candidate): array => [$candidate->moneyAccountId, $candidate->name, $candidate->accountNumberDigits], $candidates),
        );
    }

    public function testBankApiSourceIsMarkedAndClearedWithoutCommit(): void
    {
        $account = $this->account($this->company, 'Расчётный', 'RUB');
        $this->em->flush();

        $this->facade->markBankApiStatementSource($this->companyId(), (string) $account->getId(), 'alfa');
        self::assertNull($this->storedSource($account), 'фасад не коммитит — коммитит BankSync');
        $this->em->flush();
        self::assertSame('alfa', $this->storedSource($account));

        $this->facade->clearBankApiStatementSource($this->companyId(), (string) $account->getId());
        $this->em->flush();
        self::assertNull($this->storedSource($account));
    }

    public function testForeignAccountIsNotMarkedAndClearIsNoop(): void
    {
        $foreign = $this->account($this->createCompany(), 'Чужой', 'RUB');
        $foreign->markBankApiSource('alfa');
        $this->em->flush();

        $this->facade->clearBankApiStatementSource($this->companyId(), (string) $foreign->getId());
        $this->em->flush();
        self::assertSame('alfa', $this->storedSource($foreign), 'чужой счёт не тронут');

        $this->expectException(StatementImportTargetNotFoundException::class);
        $this->facade->markBankApiStatementSource($this->companyId(), (string) $foreign->getId(), 'alfa');
    }

    private function storedSource(MoneyAccount $account): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT bank_api_source FROM money_account WHERE id = :id', ['id' => (string) $account->getId()]);

        return false === $value ? null : $value;
    }

    private function account(Company $company, string $name, string $currency, ?string $number = null): MoneyAccount
    {
        $account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withName($name)
            ->withCurrency($currency)
            ->build();
        if (null !== $number) {
            $account->setAccountNumber($number);
        }
        $this->em->persist($account);

        return $account;
    }

    private function createCompany(): Company
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('statement-link-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function companyId(): string
    {
        return (string) $this->company->getId();
    }
}
