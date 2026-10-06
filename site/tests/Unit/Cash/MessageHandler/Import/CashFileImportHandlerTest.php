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
use PHPUnit\Framework\TestCase;
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

        $handler = new CashFileImportHandler($entityManager, $this->createImportService());
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

        $handler = new CashFileImportHandler($entityManager, $this->createImportService());
        $handler(new CashFileImportMessage((string) $job->getId()));

        self::assertSame(CashFileImportJob::STATUS_PROCESSING, $job->getStatus());
        self::assertNull($job->getErrorMessage());
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
