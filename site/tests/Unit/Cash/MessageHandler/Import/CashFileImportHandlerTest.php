<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\MessageHandler\Import;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\MessageHandler\Import\CashFileImportHandler;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\File\CashFileRowNormalizer;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Repository\CounterpartyRepository;
use App\Shared\Service\Storage\LegacyXlsConverter;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\TemporaryFileFactory;
use App\Shared\Service\Storage\TemporaryLocalFile;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class CashFileImportHandlerTest extends TestCase
{
    public function testFailedImportStoresReadableErrorWithoutDebugMarkers(): void
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $foreignAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString())),
            MoneyAccountType::BANK,
            'Foreign account',
            'RUB',
        );
        $job = new CashFileImportJob(
            Uuid::uuid4()->toString(),
            $company,
            $foreignAccount,
            'file',
            'transactions.csv',
            'file-hash',
            [],
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($job);
        $entityManager->method('isOpen')->willReturn(true);

        $handler = $this->createHandler($entityManager);
        $handler(new CashFileImportMessage((string) $job->getId()));

        self::assertSame(CashFileImportJob::STATUS_FAILED, $job->getStatus());
        $error = (string) $job->getErrorMessage();
        self::assertStringContainsString('Счёт импорта принадлежит другой компании.', $error);
        self::assertStringContainsString(\DomainException::class, $error);
        self::assertStringNotContainsString('DBG:', $error);
    }

    public function testSkippedNotQueuedJobKeepsErrorMessageUntouched(): void
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Account', 'RUB');
        $job = new CashFileImportJob(Uuid::uuid4()->toString(), $company, $account, 'file', 'a.csv', 'hash', []);
        $job->start();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($job);
        $entityManager->method('isOpen')->willReturn(true);

        $handler = $this->createHandler($entityManager);
        $handler(new CashFileImportMessage((string) $job->getId()));

        self::assertSame(CashFileImportJob::STATUS_PROCESSING, $job->getStatus());
        self::assertNull($job->getErrorMessage());
    }

    public function testFailureOnClosedEntityManagerIsRecordedThroughFreshManagerAndLogged(): void
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $foreignAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString())),
            MoneyAccountType::BANK,
            'Foreign account',
            'RUB',
        );
        $jobId = Uuid::uuid4()->toString();
        $job = new CashFileImportJob($jobId, $company, $foreignAccount, 'file', 'a.csv', 'hash', []);
        $freshJob = new CashFileImportJob($jobId, $company, $foreignAccount, 'file', 'a.csv', 'hash', []);
        $freshJob->start();

        $closedManager = $this->createMock(EntityManagerInterface::class);
        $closedManager->method('find')->willReturn($job);
        $closedManager->method('isOpen')->willReturn(false);

        $freshManager = $this->createMock(EntityManagerInterface::class);
        $freshManager->method('find')->willReturn($freshJob);
        $freshManager->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $registry->method('getManager')->willReturn($freshManager);

        $logger = $this->createMock(LoggerInterface::class);
        // Чужой счёт — ошибка входа (DomainException): warning, не инцидент.
        $logger->expects(self::once())->method('log')->with(
            LogLevel::WARNING,
            'Cash file import failed',
            self::callback(static fn (array $context): bool => $jobId === $context['jobId']
                && $company->getId() === $context['companyId']
                && \DomainException::class === $context['exceptionClass']
                // Текст исключения не логируется: только класс и место.
                && ['jobId', 'companyId', 'exceptionClass', 'exceptionAt'] === array_keys($context)),
        );

        $handler = new CashFileImportHandler(
            $closedManager,
            $this->createImportService(),
            $registry,
            new ImportLogger($freshManager),
            $logger,
        );
        $handler(new CashFileImportMessage($jobId));

        self::assertSame(CashFileImportJob::STATUS_FAILED, $freshJob->getStatus());
        self::assertStringContainsString(\DomainException::class, (string) $freshJob->getErrorMessage());
    }

    private function createHandler(EntityManagerInterface $entityManager): CashFileImportHandler
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($entityManager);

        return new CashFileImportHandler(
            $entityManager,
            $this->createImportService(),
            $registry,
            new ImportLogger($entityManager),
            new NullLogger(),
        );
    }

    private function createImportService(): CashFileImportService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $objectStorage = $this->createMock(ObjectStorageInterface::class);
        $facade = (new \ReflectionClass(FinancialResponsibilityCenterFacade::class))->newInstanceWithoutConstructor();

        return new CashFileImportService(
            new CashFileRowNormalizer(),
            new CounterpartyNameNormalizer(),
            $this->createMock(CounterpartyRepository::class),
            $this->createMock(CashTransactionRepository::class),
            new ImportLogger($entityManager),
            $entityManager,
            (new \ReflectionClass(AccountBalanceService::class))->newInstanceWithoutConstructor(),
            $objectStorage,
            new TemporaryLocalFile($objectStorage),
            new LegacyXlsConverter(new TemporaryFileFactory(), \dirname(__DIR__, 5)),
            new CashTransactionResponsibilityCenterResolver($facade),
        );
    }
}
