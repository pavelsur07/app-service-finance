<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Transaction;

use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Counterparty;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Enum\CounterpartyType;
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
            (new CounterpartyNameNormalizer())->normalize('Client'),
            CounterpartyType::LEGAL_ENTITY
        );
        $systemPair = $this->persistSystemPair($company);

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
        $this->assertSame($systemPair['project']->getId(), $tx->getProjectDirection()?->getId());
        $this->assertSame($systemPair['center']->getId(), $tx->getResponsibilityCenterId());
        $this->assertSame('telegram', $tx->getImportSource());
        $this->assertSame('telegram:service:test', $tx->getExternalId());
        $this->assertSame('telegram:dedupe:test', $tx->getDedupeHash());
        $this->assertSame(['source' => 'telegram', 'message_id' => 12345], $tx->getRawData());

        // Повторный импорт той же операции не должен пересоздавать разбивку: ручной
        // разбор пользователя пережил бы иначе ровно один прогон импорта и исчез молча.
        $tx->replaceSplits([
            new CashTransactionSplit($tx, $category, '6', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($tx, $this->extraCategory($company), '4', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();
        $splitIds = array_map(
            static fn (CashTransactionSplit $split): string => $split->getId(),
            $tx->getSplits()->toArray(),
        );

        $repeated = $this->txService->add($dto);

        $this->assertSame($tx->getId(), $repeated->getId(), 'Повторный импорт обязан вернуть ту же операцию.');
        $this->assertCount(2, $repeated->getSplits(), 'Разбивка не должна схлопываться повторным импортом.');
        $this->assertSame(
            $splitIds,
            array_map(
                static fn (CashTransactionSplit $split): string => $split->getId(),
                $repeated->getSplits()->toArray(),
            ),
            'Строки не должны пересоздаваться: это стёрло бы источник и историю.',
        );
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
        $systemPair = $this->persistSystemPair($company);

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
        $this->assertSame($systemPair['project']->getId(), $tx->getProjectDirection()?->getId());
        $this->assertSame($systemPair['center']->getId(), $tx->getResponsibilityCenterId());
        $this->assertNull($tx->getImportSource());
        $this->assertNull($tx->getExternalId());
        $this->assertNull($tx->getDedupeHash());
        $this->assertSame([], $tx->getRawData());
    }

    public function testAddPersistsExplicitAllowedResponsibilityPair(): void
    {
        $user = UserBuilder::aUser()
            ->withEmail('explicit-pair@example.com')
            ->withPasswordHash('pass')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Explicit Pair')
            ->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $customProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $customCenter = new FinancialResponsibilityCenter($company->getId(), 'CFO_CUSTOM', 'Краснодар');

        $this->persistSystemPair($company);
        foreach ([$user, $company, $account, $customProject, $customCenter] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject($company->getId(), $customProject, $customCenter));
        $this->em->flush();

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::OUTFLOW;
        $dto->amount = '20';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-12');
        $dto->projectDirectionId = $customProject->getId();
        $dto->responsibilityCenterId = $customCenter->getId();

        $tx = $this->txService->add($dto);

        self::assertSame($customProject->getId(), $tx->getProjectDirection()?->getId());
        self::assertSame($customCenter->getId(), $tx->getResponsibilityCenterId());
    }

    public function testAddRejectsPartialResponsibilityPairBeforePersist(): void
    {
        $user = UserBuilder::aUser()
            ->withEmail('partial-pair@example.com')
            ->withPasswordHash('pass')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Partial Pair')
            ->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Проект');

        $this->persistSystemPair($company);
        foreach ([$user, $company, $account, $project] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::OUTFLOW;
        $dto->amount = '20';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-12');
        $dto->projectDirectionId = $project->getId();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Укажите проект и ЦФО.');

        $this->txService->add($dto);
    }

    public function testUpdatePreservesUnchangedResponsibilityPair(): void
    {
        $user = UserBuilder::aUser()
            ->withEmail('update-pair@example.com')
            ->withPasswordHash('pass')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->withName('Update Pair')
            ->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $customProject = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Продажа компьютеров');
        $customCenter = new FinancialResponsibilityCenter($company->getId(), 'CFO_UPDATE', 'Ростов');

        $this->persistSystemPair($company);
        foreach ([$user, $company, $account, $customProject, $customCenter] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new FinancialResponsibilityCenterProject($company->getId(), $customProject, $customCenter));
        $this->em->flush();

        $create = new CashTransactionDTO();
        $create->companyId = $company->getId();
        $create->moneyAccountId = $account->getId();
        $create->direction = CashDirection::OUTFLOW;
        $create->amount = '30';
        $create->currency = 'USD';
        $create->occurredAt = new \DateTimeImmutable('2024-01-13');
        $create->projectDirectionId = $customProject->getId();
        $create->responsibilityCenterId = $customCenter->getId();
        $tx = $this->txService->add($create);

        $update = new CashTransactionDTO();
        $update->moneyAccountId = $account->getId();
        $update->direction = CashDirection::OUTFLOW;
        $update->amount = '31';
        $update->currency = 'USD';
        $update->occurredAt = new \DateTimeImmutable('2024-01-13');
        $update->description = 'Updated only amount';

        $this->txService->update($tx, $update);

        self::assertSame('31', $tx->getAmount());
        self::assertSame('Updated only amount', $tx->getDescription());
        self::assertSame($customProject->getId(), $tx->getProjectDirection()?->getId());
        self::assertSame($customCenter->getId(), $tx->getResponsibilityCenterId());
    }

    public function testAddRejectsMoneyAccountFromAnotherCompany(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $dto = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['foreignAccount']->getId());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Счёт не найден.');

        $this->txService->add($dto);
    }

    public function testAddRejectsCounterpartyFromAnotherCompany(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $dto = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['account']->getId());
        $dto->counterpartyId = $fixture['foreignCounterparty']->getId();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Контрагент не найден.');

        $this->txService->add($dto);
    }

    public function testAddRejectsCashflowCategoryFromAnotherCompany(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $dto = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['account']->getId());
        $dto->cashflowCategoryId = $fixture['foreignCategory']->getId();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Категория ДДС не найдена.');

        $this->txService->add($dto);
    }

    public function testUpdateRejectsMoneyAccountFromAnotherCompany(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $create = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['account']->getId());
        $transaction = $this->txService->add($create);

        $update = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['foreignAccount']->getId());
        $update->amount = '15.00';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Счёт не найден.');

        $this->txService->update($transaction, $update);
    }

    public function testAddRejectsCurrencyDifferentFromAccountCurrency(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $dto = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['account']->getId());
        $dto->currency = 'EUR';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Валюта транзакции должна совпадать с валютой счёта.');

        $this->txService->add($dto);
    }

    public function testUpdateChangesAccountAndDerivedCurrencyTogether(): void
    {
        $fixture = $this->tenantSafetyFixture();
        $create = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['account']->getId());
        $transaction = $this->txService->add($create);

        $update = $this->tenantSafetyDto($fixture['company']->getId(), $fixture['eurAccount']->getId());
        $update->currency = 'EUR';
        $this->txService->update($transaction, $update);

        self::assertSame($fixture['eurAccount']->getId(), $transaction->getMoneyAccount()->getId());
        self::assertSame('EUR', $transaction->getCurrency());
    }

    /**
     * @return array{project: ProjectDirection, center: FinancialResponsibilityCenter}
     */
    private function persistSystemPair(\App\Company\Entity\Company $company): array
    {
        $project = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $center = new FinancialResponsibilityCenter(
            $company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->em->persist($project);
        $this->em->persist($center);
        $this->em->persist(new FinancialResponsibilityCenterProject($company->getId(), $project, $center));

        return ['project' => $project, 'center' => $center];
    }

    private function extraCategory(\App\Company\Entity\Company $company): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Прочее '.substr(Uuid::uuid4()->toString(), 0, 8));
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * @return array{
     *     company: \App\Company\Entity\Company,
     *     account: MoneyAccount,
     *     eurAccount: MoneyAccount,
     *     foreignAccount: MoneyAccount,
     *     foreignCounterparty: Counterparty,
     *     foreignCategory: CashflowCategory
     * }
     */
    private function tenantSafetyFixture(): array
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('tenant-a-'.Uuid::uuid4().'@example.test')
            ->build();
        $foreignUser = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('tenant-b-'.Uuid::uuid4().'@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->withName('Tenant A '.Uuid::uuid4())
            ->build();
        $foreignCompany = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($foreignUser)
            ->withName('Tenant B '.Uuid::uuid4())
            ->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Tenant A account', 'USD');
        $eurAccount = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::CASH, 'Tenant A EUR account', 'EUR');
        $foreignAccount = new MoneyAccount(Uuid::uuid4()->toString(), $foreignCompany, MoneyAccountType::BANK, 'Tenant B account', 'USD');
        $foreignCounterparty = new Counterparty(
            Uuid::uuid4()->toString(),
            $foreignCompany,
            (new CounterpartyNameNormalizer())->normalize('Foreign counterparty'),
            CounterpartyType::LEGAL_ENTITY,
        );
        $foreignCategory = new CashflowCategory(Uuid::uuid4()->toString(), $foreignCompany);
        $foreignCategory->setName('Foreign category');
        $this->persistSystemPair($company);

        foreach ([$user, $foreignUser, $company, $foreignCompany, $account, $eurAccount, $foreignAccount, $foreignCounterparty, $foreignCategory] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return [
            'company' => $company,
            'account' => $account,
            'eurAccount' => $eurAccount,
            'foreignAccount' => $foreignAccount,
            'foreignCounterparty' => $foreignCounterparty,
            'foreignCategory' => $foreignCategory,
        ];
    }

    private function tenantSafetyDto(string $companyId, string $accountId): CashTransactionDTO
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = $companyId;
        $dto->moneyAccountId = $accountId;
        $dto->direction = CashDirection::OUTFLOW;
        $dto->amount = '10.00';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-15');

        return $dto;
    }
}
