<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\OAuthTokenRefresher;
use App\BankSync\Command\AlfaSandboxCheckCommand;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaApiClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaFixtureAnonymizer;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaStatementMapper;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTraceIdRecorder;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Проверка песочницы против заглушки банка: только чтение, отчёт без номеров счетов.
 */
final class AlfaSandboxCheckCommandTest extends IntegrationTestCase
{
    private const ACCOUNT_NUMBER = '40702810621234570000';

    private string $companyId;
    /** @var list<string> */
    private array $requested = [];
    private int $statementReads = 0;
    private bool $unstableUuid = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('sandbox-check-'.$this->companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build());
        $this->em->flush();
    }

    public function testReportShowsStableKeyAndMatchingSummaryWithoutWritingOrFullNumbers(): void
    {
        $connection = $this->connection('https://sandbox.alfabank.ru');
        $fixturesDir = sys_get_temp_dir().'/sandbox-check-'.Uuid::uuid4()->toString();
        $before = $this->rowCounts();

        $tester = $this->check($connection, ['--save-fixtures' => $fixturesDir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Accounts: 1', $display);
        self::assertStringContainsString('Account …0000 (RUB), 2026-10-01', $display);
        self::assertStringContainsString('uuid stable: yes; transactionId stable: yes', $display);
        self::assertStringContainsString('opening + credit − debit = closing: yes; operations count matches: yes; turnover matches lines: yes', $display);
        self::assertStringContainsString('X-TraceId: trace-1', $display);
        self::assertStringNotContainsString(self::ACCOUNT_NUMBER, $display);
        self::assertSame(2, $this->statementReads, 'день прочитан дважды');
        self::assertSame($before, $this->rowCounts(), 'команда ничего не пишет в БД');

        $files = glob($fixturesDir.'/*.json') ?: [];
        self::assertCount(4, $files, 'счета, два чтения дня, обороты');
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString(self::ACCOUNT_NUMBER, $content);
            self::assertStringNotContainsString('Ромашка', $content);
            unlink($file);
        }
        rmdir($fixturesDir);
    }

    public function testUnstableOperationKeyFailsTheCheck(): void
    {
        $this->unstableUuid = true;

        $tester = $this->check($this->connection('https://sandbox.alfabank.ru'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('uuid stable: NO', $tester->getDisplay());
    }

    public function testProductionConnectionIsRefusedWithoutBankRequests(): void
    {
        $tester = $this->check($this->connection('https://baas.alfabank.ru'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Only an Alfa sandbox connection', $tester->getDisplay());
        self::assertSame([], $this->requested);
    }

    public function testForeignOrInactiveConnectionIsRefusedWithoutBankRequests(): void
    {
        $connection = $this->connection('https://sandbox.alfabank.ru');

        $foreign = $this->command();
        $tester = new CommandTester($foreign);
        $tester->execute(['--company' => Uuid::uuid4()->toString(), '--connection' => $connection->getId(), '--date' => '2026-10-01', '--pause' => '0']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $connection->markNeedsReauth('refresh_rejected');
        $this->em->flush();
        $tester = $this->check($connection);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('needs_reauth', $tester->getDisplay());
        self::assertSame([], $this->requested);
    }

    /**
     * @param array<string, string> $options
     */
    private function check(BankConnection $connection, array $options = []): CommandTester
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--company' => $this->companyId, '--connection' => $connection->getId(), '--date' => '2026-10-01', '--pause' => '1'] + $options);

        return $tester;
    }

    private function command(): AlfaSandboxCheckCommand
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->requested[] = $path;

            return match ($path) {
                AlfaApiClient::ACCOUNTS_PATH => $this->json(['Data' => ['Account' => [[
                    'accountId' => 'acc-1',
                    'status' => 'Enabled',
                    'currency' => 'RUB',
                    'accountDescription' => 'Ромашка, расчётный',
                    'AccountDetails' => [['schemeName' => 'RU.CBR.BBAN', 'identification' => self::ACCOUNT_NUMBER]],
                ]]], 'Meta' => ['totalPages' => 1]]),
                AlfaApiClient::STATEMENT_PATH => $this->statement(),
                AlfaApiClient::SUMMARY_PATH => $this->json([
                    'openingBalance' => ['amount' => 1000, 'currencyName' => 'RUR'],
                    'closingBalance' => ['amount' => 1070, 'currencyName' => 'RUR'],
                    'creditTurnover' => ['amount' => 100, 'currencyName' => 'RUR'],
                    'debitTurnover' => ['amount' => 30, 'currencyName' => 'RUR'],
                    'creditTransactionsNumber' => 1,
                    'debitTransactionsNumber' => 1,
                ]),
                default => new MockResponse('', ['http_code' => 404]),
            };
        });
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);
        $codec = new BankCredentialsCodec($encryption);

        return new AlfaSandboxCheckCommand(
            self::getContainer()->get(BankConnectionRepository::class),
            $codec,
            new OAuthTokenRefresher($codec, self::getContainer()->get(BankAuthorizationProviderRegistry::class), new NativeClock()),
            new AlfaApiClient($http, new NullLogger(), $recorder = new AlfaTraceIdRecorder()),
            new AlfaStatementMapper(),
            new AlfaFixtureAnonymizer(),
            $recorder,
            new NativeClock(),
            static function (int $seconds): void {
            },
        );
    }

    private function statement(): MockResponse
    {
        ++$this->statementReads;
        $suffix = $this->unstableUuid ? '-'.$this->statementReads : '';

        return $this->json(['transactions' => [
            ['uuid' => 'uuid-in'.$suffix, 'transactionId' => 'tx-in', 'direction' => 'CREDIT', 'amount' => ['amount' => 100, 'currencyName' => 'RUR'], 'operationDate' => '2026-10-01T10:00:00Z', 'paymentPurpose' => 'Оплата от ООО Ромашка'],
            ['uuid' => 'uuid-out'.$suffix, 'transactionId' => 'tx-out', 'direction' => 'DEBIT', 'amount' => ['amount' => 30, 'currencyName' => 'RUR'], 'operationDate' => '2026-10-01T11:00:00Z', 'paymentPurpose' => 'Комиссия'],
        ]]);
    }

    /**
     * @param array<mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['X-TraceId' => 'trace-'.\count($this->requested)]]);
    }

    private function connection(string $baseUrl): BankConnection
    {
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withBaseUrl($baseUrl)
            ->withCredentials(new BankCredentialsCodec($encryption)->encryptOAuthTokens(new OAuthTokens('access', 'refresh', new \DateTimeImmutable('+1 hour'))))
            ->build();
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['cash_transaction', 'bank_sync_account', 'bank_sync_import_cursor', 'import_log', 'bank_sync_connection'] as $table) {
            $counts[$table] = (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        }

        return $counts;
    }
}
