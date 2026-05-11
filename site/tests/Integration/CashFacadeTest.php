<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Facade\CashFacade;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashFacadeTest extends IntegrationTestCase
{
    private CashFacade $cashFacade;
    private CashTransactionRepository $cashTransactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashFacade = self::getContainer()->get(CashFacade::class);
        $this->cashTransactionRepository = self::getContainer()->get(CashTransactionRepository::class);
    }

    public function testCreateTransactionPersistsTransactionViaPublicFacade(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, 'Client', CounterpartyType::LEGAL_ENTITY);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($counterparty);
        $this->em->flush();

        $result = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::INFLOW,
            amount: '123.45',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-02-10'),
            description: 'Facade tx',
            counterpartyId: $counterparty->getId(),
        ));

        $saved = $this->cashTransactionRepository->find($result->transactionId);

        self::assertTrue($result->created);
        self::assertFalse($result->duplicate);
        self::assertNotNull($saved);
        self::assertSame('Facade tx', $saved->getDescription());
        self::assertSame($counterparty->getId(), $saved->getCounterparty()?->getId());
    }


    public function testCreateTransactionPersistsImportAndTelegramDedupFields(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade3@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company 3')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $rawData = [
            'source' => 'telegram',
            'update_id' => 123,
            'message_id' => 456,
            'chat_id' => 789,
            'from_id' => 111,
            'message_date' => 1710000000,
            'text' => 'расход 100 такси',
        ];

        $result = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '100.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-10'),
            description: 'Telegram tx',
            importSource: 'telegram',
            externalId: 'telegram:test',
            dedupeHash: 'telegram:hash:test',
            rawData: $rawData,
        ));

        $saved = $this->cashTransactionRepository->find($result->transactionId);

        self::assertTrue($result->created);
        self::assertFalse($result->duplicate);
        self::assertNotNull($saved);
        self::assertSame('telegram', $saved->getImportSource());
        self::assertSame('telegram:test', $saved->getExternalId());
        self::assertSame('telegram:hash:test', $saved->getDedupeHash());
        self::assertSame($rawData, $saved->getRawData());
    }

    public function testCreateTransactionWithoutImportFieldsWorks(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade2@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company 2')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $result = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '77.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-01'),
            description: 'No import fields',
        ));

        $saved = $this->cashTransactionRepository->find($result->transactionId);

        self::assertTrue($result->created);
        self::assertFalse($result->duplicate);
        self::assertNotNull($saved);
        self::assertNull($saved->getImportSource());
        self::assertNull($saved->getExternalId());
        self::assertNull($saved->getDedupeHash());
        self::assertSame([], $saved->getRawData());
    }

    public function testCreateTransactionReturnsDuplicateForSameImportSourceAndExternalId(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade4@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company 4')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $first = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '100.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-10'),
            description: 'Telegram tx',
            importSource: 'telegram',
            externalId: 'telegram:dedupe',
        ));

        $second = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '100.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-10'),
            description: 'Telegram tx duplicate delivery',
            importSource: 'telegram',
            externalId: 'telegram:dedupe',
        ));

        self::assertTrue($first->created);
        self::assertFalse($first->duplicate);
        self::assertFalse($second->created);
        self::assertTrue($second->duplicate);
        self::assertSame($first->transactionId, $second->transactionId);

        $rows = $this->cashTransactionRepository->findBy([
            'company' => $company,
            'importSource' => 'telegram',
            'externalId' => 'telegram:dedupe',
        ]);

        self::assertCount(1, $rows);
    }

    public function testCreateTransactionDoesNotTreatDifferentExternalIdsAsDuplicate(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade5@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company 5')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $first = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '250.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-11'),
            description: 'Taxi',
            importSource: 'telegram',
            externalId: 'telegram:100',
        ));

        $second = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '250.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-11'),
            description: 'Taxi',
            importSource: 'telegram',
            externalId: 'telegram:101',
        ));

        self::assertTrue($first->created);
        self::assertTrue($second->created);
        self::assertFalse($first->duplicate);
        self::assertFalse($second->duplicate);
        self::assertNotSame($first->transactionId, $second->transactionId);
    }

    public function testCreateTransactionTreatsSoftDeletedImportAsDuplicate(): void
    {
        $user = UserBuilder::aUser()->withEmail('facade6@example.com')->withPasswordHash('pass')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->withName('Facade Company 6')->build();

        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $first = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '100.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-12'),
            description: 'Telegram tx initial',
            importSource: 'telegram',
            externalId: 'telegram:test',
        ));

        $stored = $this->cashTransactionRepository->find($first->transactionId);
        self::assertNotNull($stored);
        $stored->markDeleted('test-user', 'manual soft delete');
        $this->em->flush();

        $second = $this->cashFacade->createTransaction(new CreateCashTransactionCommand(
            companyId: $company->getId(),
            moneyAccountId: $account->getId(),
            direction: CashDirection::OUTFLOW,
            amount: '100.00',
            currency: 'USD',
            occurredAt: new \DateTimeImmutable('2024-03-12'),
            description: 'Telegram tx repeated webhook',
            importSource: 'telegram',
            externalId: 'telegram:test',
        ));

        self::assertFalse($second->created);
        self::assertTrue($second->duplicate);
        self::assertSame($first->transactionId, $second->transactionId);

        $rows = $this->cashTransactionRepository->findBy([
            'company' => $company,
            'importSource' => 'telegram',
            'externalId' => 'telegram:test',
        ]);

        self::assertCount(1, $rows);
    }

}
