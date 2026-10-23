<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\MessageHandler\Import;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Exception\BankApiRequestFailedException;
use App\Cash\Exception\BankApiUnavailableException;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Message\Import\BankImportMessage;
use App\Cash\MessageHandler\Import\BankImportHandler;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankConnectionRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\BankImportService;
use App\Cash\Service\Import\Bank\Provider\Alfa\AlfaStatementsProvider;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Company\Repository\CompanyMemberRepository;
use App\Company\Repository\CounterpartyRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class BankImportHandlerTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testImportsAndLogsStartAndFinish(): void
    {
        $company = $this->company();
        $provider = $this->provider(static fn (): array => []);
        $handler = $this->handler($company, $this->connection($company), $provider);

        $handler($this->message($company, 'alfa'));

        self::assertSame([
            ['INFO', 'Bank import started'],
            ['INFO', 'Bank import finished'],
        ], $this->records());
    }

    public function testMissingCompanyIsUnrecoverable(): void
    {
        $company = $this->company();
        $handler = $this->handler(null, $this->connection($company), $this->provider(static fn (): array => []));

        $this->assertUnrecoverable($handler, $this->message($company, 'alfa'), 'Bank import: company not found');
    }

    public function testMissingConnectionIsUnrecoverable(): void
    {
        $company = $this->company();
        $handler = $this->handler($company, null, $this->provider(static fn (): array => []));

        $this->assertUnrecoverable($handler, $this->message($company, 'alfa'), 'Bank import: active bank connection not found');
    }

    public function testUnsupportedBankCodeIsUnrecoverable(): void
    {
        $company = $this->company();
        $handler = $this->handler($company, $this->connection($company), $this->provider(static fn (): array => []));

        $this->assertUnrecoverable($handler, $this->message($company, 'sber'), 'Bank import: unsupported bank code');
    }

    public function testTemporaryBankFailureIsLeftToTransportRetry(): void
    {
        $company = $this->company();
        $provider = $this->provider(static fn (): never => throw new BankApiUnavailableException('alfa', 503));
        $handler = $this->handler($company, $this->connection($company), $provider);

        try {
            $handler($this->message($company, 'alfa'));
            self::fail('BankApiUnavailableException expected.');
        } catch (BankApiUnavailableException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertContains(['WARNING', 'Bank import: bank API temporarily unavailable'], $this->records());
        self::assertFalse($this->logs->hasRecords(Level::Error));
    }

    public function testRejectedRequestIsUnrecoverable(): void
    {
        $company = $this->company();
        $provider = $this->provider(static fn (): never => throw new BankApiRequestFailedException('alfa', 401));
        $handler = $this->handler($company, $this->connection($company), $provider);

        try {
            $handler($this->message($company, 'alfa'));
            self::fail('UnrecoverableMessageHandlingException expected.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertInstanceOf(BankApiRequestFailedException::class, $exception->getPrevious());
        }

        self::assertContains(['ERROR', 'Bank import: bank API rejected the request'], $this->records());
    }

    /**
     * @return list<array{string, string}>
     */
    private function records(): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): array => [$record->level->getName(), $record->message],
            $this->logs->getRecords(),
        ));
    }

    private function assertUnrecoverable(BankImportHandler $handler, BankImportMessage $message, string $reason): void
    {
        try {
            $handler($message);
            self::fail('UnrecoverableMessageHandlingException expected.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }

        $errors = array_values(array_filter(
            $this->logs->getRecords(),
            static fn (LogRecord $record): bool => Level::Error === $record->level,
        ));
        self::assertCount(1, $errors);
        self::assertSame($reason, $errors[0]->message);
        self::assertSame([
            'import_id' => $message->getImportId(),
            'company_id' => $message->getCompanyId(),
            'bank_code' => $message->getBankCode(),
        ], $errors[0]->context);
    }

    private function handler(?Company $company, ?BankConnection $connection, AlfaStatementsProvider $provider): BankImportHandler
    {
        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturn($company);

        $connections = $this->getMockBuilder(BankConnectionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findActiveByCompanyAndBankCode'])
            ->getMock();
        $connections->method('findActiveByCompanyAndBankCode')->willReturn($connection);

        $companyFacade = new CompanyFacade(
            $companyRepository,
            (new \ReflectionClass(CreateCompanyOwnerAccountAction::class))->newInstanceWithoutConstructor(),
            $this->createMock(CounterpartyRepository::class),
            $this->createMock(CompanyMemberRepository::class),
            $this->createMock(ProjectDirectionRepository::class),
            $this->createMock(UserRepository::class),
        );

        $logger = new Logger('test', [$this->logs]);

        return new BankImportHandler($companyFacade, $connections, $this->importService(), $provider, $logger);
    }

    private function importService(): BankImportService
    {
        $responsibilityCenters = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $responsibilityCenters
            ->method('findGeneralPair')
            ->willReturn(new FinancialResponsibilityCenterProjectDTO(Uuid::uuid4()->toString(), Uuid::uuid4()->toString()));

        $entityManager = $this->createMock(EntityManagerInterface::class);

        return new BankImportService(
            $this->getMockBuilder(BankImportCursorRepository::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(MoneyAccountRepository::class)->disableOriginalConstructor()->getMock(),
            $this->getMockBuilder(CashTransactionRepository::class)->disableOriginalConstructor()->getMock(),
            $entityManager,
            new NullLogger(),
            new ImportLogger($entityManager),
            new CashTransactionResponsibilityCenterResolver($responsibilityCenters),
            new CashImportAccountLock($this->createMock(Connection::class)),
        );
    }

    /**
     * @param callable(): array<mixed> $accounts
     */
    private function provider(callable $accounts): AlfaStatementsProvider
    {
        $provider = $this->getMockBuilder(AlfaStatementsProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAccounts'])
            ->getMock();
        $provider->method('getAccounts')->willReturnCallback($accounts);

        return $provider;
    }

    private function company(): Company
    {
        return new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
    }

    private function connection(Company $company): BankConnection
    {
        return new BankConnection(Uuid::uuid4()->toString(), $company, 'alfa', 'api-key', 'https://bank.test');
    }

    private function message(Company $company, string $bankCode): BankImportMessage
    {
        return new BankImportMessage((string) $company->getId(), $bankCode);
    }
}
