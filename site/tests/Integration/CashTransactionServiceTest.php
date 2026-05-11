<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Company\Enum\CounterpartyType;
use App\Cash\DTO\CashTransactionDTO;
use App\Company\Entity\Counterparty;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CashTransactionServiceTest extends IntegrationTestCase
{
    private CashTransactionService $txService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->txService = self::getContainer()->get(CashTransactionService::class);
    }

    public function testAddPersistsAllFields(): void
    {
        $user = UserBuilder::aUser()
            ->withEmail('t@example.com')
            ->withPasswordHash('pass')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Test')
            ->build();

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main',
            'USD'
        );
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Sales');

        $counterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $company,
            'Client',
            CounterpartyType::LEGAL_ENTITY
        );

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($category);
        $this->em->persist($counterparty);
        $this->em->flush();

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::INFLOW;
        $dto->amount = '10';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-10');
        $dto->description = 'Test tx';
        $dto->cashflowCategoryId = $category->getId();
        $dto->counterpartyId = $counterparty->getId();
        $dto->importSource = 'telegram';
        $dto->externalId = 'telegram:service:test';
        $dto->dedupeHash = 'telegram:dedupe:test';
        $dto->rawData = ['source' => 'telegram', 'message_id' => 12345];

        $tx = $this->txService->add($dto);

        $this->assertSame('Test tx', $tx->getDescription());
        $this->assertSame($category->getId(), $tx->getCashflowCategory()->getId());
        $this->assertSame($counterparty->getId(), $tx->getCounterparty()->getId());
        $this->assertSame('telegram', $tx->getImportSource());
        $this->assertSame('telegram:service:test', $tx->getExternalId());
        $this->assertSame('telegram:dedupe:test', $tx->getDedupeHash());
        $this->assertSame(['source' => 'telegram', 'message_id' => 12345], $tx->getRawData());
    }

    public function testAddWithoutImportFieldsKeepsBackwardCompatibility(): void
    {
        $user = UserBuilder::aUser()
            ->withEmail('compat@example.com')
            ->withPasswordHash('pass')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Compat')
            ->build();

        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main',
            'USD'
        );
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::OUTFLOW;
        $dto->amount = '11.50';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-11');
        $dto->description = 'Legacy tx';

        $tx = $this->txService->add($dto);

        $this->assertSame('Legacy tx', $tx->getDescription());
        $this->assertNull($tx->getImportSource());
        $this->assertNull($tx->getExternalId());
        $this->assertNull($tx->getDedupeHash());
        $this->assertSame([], $tx->getRawData());
    }
}
