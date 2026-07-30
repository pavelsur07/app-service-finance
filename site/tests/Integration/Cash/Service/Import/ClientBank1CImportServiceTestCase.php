<?php

namespace App\Tests\Integration\Cash\Service\Import;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\CounterpartyRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Ramsey\Uuid\Uuid;

abstract class ClientBank1CImportServiceTestCase extends IntegrationTestCase
{
    protected ClientBank1CImportService $service;
    protected CashTransactionRepository $transactionRepository;
    protected CounterpartyRepository $counterpartyRepository;
    protected MoneyAccount $account;
    protected Company $company;
    protected ProjectDirection $systemProject;
    protected FinancialResponsibilityCenter $systemCenter;
    /** @var AccountBalanceService&MockObject */
    protected AccountBalanceService $accountBalanceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transactionRepository = self::getContainer()->get(CashTransactionRepository::class);
        $this->counterpartyRepository = self::getContainer()->get(CounterpartyRepository::class);
        $this->accountBalanceService = $this->createMock(AccountBalanceService::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('import@example.com');
        $user->setPassword('password');

        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Test Company');

        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main account', 'RUB');
        $this->account->setAccountNumber('40702810900000000001');
        $this->systemProject = new ProjectDirection(
            Uuid::uuid4()->toString(),
            $this->company,
            'Общий',
            ProjectDirection::CODE_GENERAL,
        );
        $this->systemCenter = new FinancialResponsibilityCenter(
            $this->company->getId(),
            FinancialResponsibilityCenter::CODE_GENERAL,
            FinancialResponsibilityCenter::NAME_GENERAL,
        );

        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->em->persist($this->account);
        $this->em->persist($this->systemProject);
        $this->em->persist($this->systemCenter);
        $this->em->persist(new FinancialResponsibilityCenterProject(
            $this->company->getId(),
            $this->systemProject,
            $this->systemCenter,
        ));
        $this->em->flush();

        $activeCompanyService = new ImportTestActiveCompanyService($this->company);

        $this->service = new ClientBank1CImportService(
            $activeCompanyService,
            new CounterpartyNameNormalizer(),
            $this->counterpartyRepository,
            $this->transactionRepository,
            new ImportLogger($this->em),
            $this->em,
            $this->accountBalanceService,
            self::getContainer()->get(CashTransactionResponsibilityCenterResolver::class),
        );
    }
}

final class ImportTestActiveCompanyService extends ActiveCompanyService
{
    public function __construct(private Company $company)
    {
    }

    public function getActiveCompany(): Company
    {
        return $this->company;
    }
}
