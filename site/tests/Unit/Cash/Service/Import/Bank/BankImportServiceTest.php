<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\Bank;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Entity\Bank\BankImportCursor;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\BankImportService;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class BankImportServiceTest extends TestCase
{
    public function testProviderImportCreatesTransactionWithSystemResponsibilityPair(): void
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Основной', 'RUB');
        $account->setAccountNumber('40702810900000000001');
        $connection = new BankConnection(Uuid::uuid4()->toString(), $company, 'alfa', 'api-key', 'https://bank.test');
        $cursor = new BankImportCursor(Uuid::uuid4()->toString(), $company, 'alfa', '40702810900000000001');
        $cursor->setLastImportedDate(new \DateTimeImmutable('today'));

        $projectId = Uuid::uuid4()->toString();
        $centerId = Uuid::uuid4()->toString();
        $systemProject = new ProjectDirection($projectId, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $persistedTransactions = [];

        $cursorRepository = $this->getMockBuilder(BankImportCursorRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrCreate', 'save'])
            ->getMock();
        $cursorRepository
            ->method('getOrCreate')
            ->willReturn($cursor);

        $moneyAccountRepository = $this->getMockBuilder(MoneyAccountRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByCompanyAndAccountNumber'])
            ->getMock();
        $moneyAccountRepository
            ->method('findOneByCompanyAndAccountNumber')
            ->willReturn($account);

        $transactionRepository = $this->getMockBuilder(CashTransactionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByImport'])
            ->getMock();
        $transactionRepository
            ->method('findOneByImport')
            ->willReturn(null);

        $provider = $this->createMock(BankStatementsProviderInterface::class);
        $provider
            ->method('getAccounts')
            ->willReturn([
                'Data' => [
                    'Account' => [[
                        'AccountDetails' => [[
                            'identification' => '40702810900000000001',
                        ]],
                    ]],
                ],
            ]);
        $provider
            ->method('getTransactions')
            ->willReturnOnConsecutiveCalls(
                [
                    'transactions' => [[
                        'uuid' => 'bank-tx-1',
                        'operationDate' => '2026-07-18T10:00:00+00:00',
                        'amount' => [
                            'amount' => 1000,
                            'currencyName' => 'RUB',
                        ],
                        'direction' => 'CREDIT',
                        'paymentPurpose' => 'Bank import',
                    ]],
                ],
                ['transactions' => []],
            );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getReference')
            ->with(ProjectDirection::class, $projectId)
            ->willReturn($systemProject);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedTransactions): void {
                if ($entity instanceof CashTransaction) {
                    $persistedTransactions[] = $entity;
                }
            });

        $service = new BankImportService(
            $cursorRepository,
            $moneyAccountRepository,
            $transactionRepository,
            $entityManager,
            new NullLogger(),
            new ImportLogger($entityManager),
            $this->createResolver($projectId, $centerId),
        );

        $service->importCompany('alfa', $company, $connection, $provider);

        self::assertCount(1, $persistedTransactions);
        self::assertSame($projectId, $persistedTransactions[0]->getProjectDirection()?->getId());
        self::assertSame($centerId, $persistedTransactions[0]->getResponsibilityCenterId());
    }

    private function createResolver(string $projectId, string $centerId): CashTransactionResponsibilityCenterResolver
    {
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade
            ->method('findGeneralPair')
            ->willReturn(new FinancialResponsibilityCenterProjectDTO($projectId, $centerId));

        return new CashTransactionResponsibilityCenterResolver($facade);
    }
}
