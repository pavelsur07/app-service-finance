<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\CounterpartyRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryLocalFile;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CashFileImportServiceTest extends TestCase
{
    public function testDedupeHashIsStableForSameInput(): void
    {
        $service = new CashFileImportService(
            $this->createMock(CashFileRowNormalizer::class),
            new CounterpartyNameNormalizer(),
            $this->createMock(CounterpartyRepository::class),
            $this->createMock(CashTransactionRepository::class),
            new ImportLogger($this->createMock(EntityManagerInterface::class)),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AccountBalanceService::class),
            $objectStorage = $this->createMock(ObjectStorageInterface::class),
            new TemporaryLocalFile($objectStorage),
            $this->createResolver(Uuid::uuid4()->toString(), Uuid::uuid4()->toString()),
        );

        $method = new \ReflectionMethod(CashFileImportService::class, 'makeDedupeHash');
        $method->setAccessible(true);

        $occurredAt = new \DateTimeImmutable('2025-12-01', new \DateTimeZone('UTC'));

        $hashOne = $method->invoke(
            $service,
            'company-id',
            'account-id',
            $occurredAt,
            1000,
            'Назначение платежа'
        );
        $hashTwo = $method->invoke(
            $service,
            'company-id',
            'account-id',
            $occurredAt,
            1000,
            'Назначение платежа'
        );

        self::assertSame($hashOne, $hashTwo);
    }

    public function testImportedFileTransactionReceivesSystemResponsibilityPair(): void
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Основной', 'RUB');
        $projectId = Uuid::uuid4()->toString();
        $centerId = Uuid::uuid4()->toString();
        $systemProject = new ProjectDirection($projectId, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $persistedTransactions = [];

        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $objectStorage
            ->method('exists')
            ->with('cash-file-imports/file-hash.csv')
            ->willReturn(true);
        $objectStorage
            ->method('readStream')
            ->with('cash-file-imports/file-hash.csv')
            ->willReturnCallback(static function () {
                $stream = fopen('php://memory', 'rb+');
                self::assertIsResource($stream);
                fwrite($stream, "Дата,Сумма,Описание\n2026-07-01,1000.00,Импорт\n");
                rewind($stream);

                return $stream;
            });

        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository
            ->method('existsByCompanyAndDedupe')
            ->willReturn(false);

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

        $accountBalanceService = $this->createMock(AccountBalanceService::class);
        $accountBalanceService
            ->expects(self::once())
            ->method('recalculateDailyRange');

        $service = new CashFileImportService(
            new CashFileRowNormalizer(),
            new CounterpartyNameNormalizer(),
            $this->createMock(CounterpartyRepository::class),
            $transactionRepository,
            new ImportLogger($entityManager),
            $entityManager,
            $accountBalanceService,
            $objectStorage,
            new TemporaryLocalFile($objectStorage),
            $this->createResolver($projectId, $centerId),
        );

        $job = new CashFileImportJob(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            'file',
            'transactions.csv',
            'file-hash',
            [
                'date' => 'Дата',
                'amount' => 'Сумма',
                'description' => 'Описание',
            ],
            ['stored_ext' => 'csv'],
        );

        $service->import($job);

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
