<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Command\SyncAlfaConsentsCommand;
use App\BankSync\Domain\Consent\ConsentExpiryPolicy;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaConsentsClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaIdConfig;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\OAuth\OAuthTokenEndpointClient;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\TestCertificates;
use App\Tests\Support\BankSync\TestIdTokenSigner;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Сверка согласий Альфы против заглушки банка (MockHttpClient): token endpoint (Client
 * Credentials) и постраничный `client-consents`.
 */
final class SyncAlfaConsentsCommandTest extends IntegrationTestCase
{
    private const SANDBOX = 'https://sandbox.alfabank.ru';
    private const CLIENT_ID = '0cee0683-85ae-49f2-a63d-29f97aad1911';
    private const CLIENT_SECRET = 'app-secret-value';
    private const TODAY = '2026-10-27 08:00:00';

    private string $companyId;
    private TestHandler $logs;
    private TestCertificates $files;
    private Connection $worker;
    /** @var list<array{method: string, url: string, body: string, auth: string}> */
    private array $requests = [];
    /** @var list<array<string, mixed>> согласия «у банка» */
    private array $bankConsents = [];
    private ?MockResponse $tokenFailure = null;
    /** @var array<int, MockResponse> ответ на страницу по offset */
    private array $pageFailures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-consents-'.$this->companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build());
        $this->em->flush();
        $this->logs = new TestHandler();
        $this->files = new TestCertificates();
        $this->worker = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        $this->worker->close();
        $this->files->remove();

        parent::tearDown();
    }

    public function testActiveConsentDatesAreStoredAndExpiringOnesWarned(): void
    {
        $far = $this->connection('user-far');
        $soon = $this->connection('user-soon');
        $this->bankConsents = [
            $this->consent('user-far', true, '2027-09-01'),
            $this->consent('user-far', true, '2027-10-20'),
            $this->consent('user-soon', true, '2026-11-15'),
        ];

        [$exit, $display] = $this->runSync();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame('2027-09-01', $this->reload($far)->getConsentExpiresAt()?->format('Y-m-d'), 'самое раннее действующее');
        self::assertSame('2026-11-15', $this->reload($soon)->getConsentExpiresAt()?->format('Y-m-d'));
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reload($soon)->getStatus());
        self::assertStringContainsString('2 date(s) updated, 1 expiring within 30 days', $display);
        self::assertTrue($this->logs->hasWarningThatContains('consents expiring, expired or not found'));
        self::assertSame([$soon->getId()], $this->warningContext()['expiring_connection_ids']);
        self::assertFalse($this->logs->hasErrorRecords());
        $this->assertNoPersonalDataLeaked($display);

        $token = $this->requests[0];
        self::assertSame('https://sandbox.alfabank.ru/oidc/token', $token['url']);
        parse_str($token['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET, 'scope' => AlfaConsentsClient::CLIENT_CREDENTIALS_SCOPE], $form);
        self::assertStringStartsWith('https://sandbox.alfabank.ru/oidc/client-consents?clientId='.self::CLIENT_ID.'&limit=1000&offset=0', $this->requests[1]['url']);
        self::assertSame('Bearer app-access', $this->requests[1]['auth']);
    }

    public function testAllConsentsInactiveOrExpiredRevokeTheConnection(): void
    {
        $connection = $this->connection('user-gone');
        $this->bankConsents = [$this->consent('user-gone', false, '2027-05-01'), $this->consent('user-gone', true, '2026-10-26')];

        [$exit] = $this->runSync();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(BankConnectionStatus::REVOKED, $this->reload($connection)->getStatus());
        self::assertSame(SyncAlfaConsentsCommand::STATUS_REASON, $this->reload($connection)->getStatusReason());
        self::assertSame([$connection->getId()], $this->warningContext()['revoked_connection_ids']);
    }

    public function testConnectionWhoseTokensAreLockedIsNotRevokedNow(): void
    {
        $connection = $this->connection('user-gone');
        $this->bankConsents = [$this->consent('user-gone', false, '2027-05-01')];
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($connection->getId(), 0));

        [$exit] = $this->runSync();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reload($connection)->getStatus());
        self::assertSame([$connection->getId()], $this->warningContext()['busy_connection_ids']);
    }

    public function testSubjectMissingFromBankAnswerChangesNothing(): void
    {
        $connection = $this->connection('user-unknown');
        $connection->updateConsentExpiresAt(new \DateTimeImmutable('2027-01-01'));
        $this->em->flush();
        $this->bankConsents = [$this->consent('someone-else', true, '2027-09-01')];

        [$exit, $display] = $this->runSync();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reload($connection)->getStatus());
        self::assertSame('2027-01-01', $this->reload($connection)->getConsentExpiresAt()?->format('Y-m-d'));
        self::assertStringContainsString('1 not found in the bank', $display);
        self::assertSame([$connection->getId()], $this->warningContext()['not_found_connection_ids']);
    }

    public function testAllPagesAreReadBeforeWriting(): void
    {
        $connection = $this->connection('user-on-page-2');
        for ($i = 0; $i < AlfaConsentsClient::PAGE_SIZE; ++$i) {
            $this->bankConsents[] = $this->consent('filler-'.$i, true, '2027-09-01');
        }
        $this->bankConsents[] = $this->consent('user-on-page-2', true, '2027-08-01');

        $this->runSync();

        self::assertSame('2027-08-01', $this->reload($connection)->getConsentExpiresAt()?->format('Y-m-d'));
        self::assertCount(3, $this->requests, 'токен + две страницы');
    }

    public function testFailureOnLaterPageWritesNothing(): void
    {
        $connection = $this->connection('user-gone');
        for ($i = 0; $i < AlfaConsentsClient::PAGE_SIZE; ++$i) {
            $this->bankConsents[] = $this->consent('user-gone', false, '2027-09-01');
        }
        $this->bankConsents[] = $this->consent('other', true, '2027-09-01');
        $this->pageFailures[AlfaConsentsClient::PAGE_SIZE] = new MockResponse('', ['http_code' => 503]);

        [$exit] = $this->runSync();

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reload($connection)->getStatus());
        self::assertTrue($this->logs->hasWarningThatContains('bank temporarily unavailable'));
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testRejectedApplicationCredentialsAreOneErrorAndNothingChanges(): void
    {
        $connection = $this->connection('user-gone');
        $this->bankConsents = [$this->consent('user-gone', false, '2027-05-01')];
        $this->tokenFailure = new MockResponse('{"error":"invalid_client"}', ['http_code' => 401]);

        [$exit] = $this->runSync();

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reload($connection)->getStatus());
        $errors = array_values(array_filter($this->logs->getRecords(), static fn ($record): bool => $record->level->value >= 400));
        self::assertCount(1, $errors);
        self::assertStringNotContainsString(self::CLIENT_SECRET, json_encode($errors[0]->context, \JSON_THROW_ON_ERROR));
    }

    public function testNotConfiguredIsSuccessWithoutRequests(): void
    {
        $this->connection('user-far');

        $tester = new CommandTester($this->command(new AlfaIdConfig(null, null, null, null, null, null)));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('not configured', $tester->getDisplay());
        self::assertSame([], $this->requests);
    }

    public function testRevokedAndSubjectlessConnectionsAreNotAsked(): void
    {
        $this->connection(null);
        $revoked = $this->connection('user-gone');
        $revoked->markRevoked('consent_expired');
        $this->em->flush();

        [$exit, $display] = $this->runSync();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('no connections to check', $display);
        self::assertSame([], $this->requests, 'платных или лишних запросов к банку нет');
    }

    /**
     * @return array{int, string}
     */
    private function runSync(): array
    {
        $tester = new CommandTester($this->command($this->sandboxConfig()));
        $exit = $tester->execute([]);

        return [$exit, $tester->getDisplay()];
    }

    private function command(AlfaIdConfig $config): SyncAlfaConsentsCommand
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = $options['normalized_headers']['authorization'][0] ?? '';
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : '',
                'auth' => substr((string) $headers, \strlen('Authorization: ')),
            ];
            if (str_ends_with($url, '/oidc/token')) {
                return $this->tokenFailure ?? new MockResponse('{"access_token":"app-access","token_type":"Bearer","expires_in":3600}');
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $offset = (int) ($query['offset'] ?? 0);

            return $this->pageFailures[$offset] ?? new MockResponse(json_encode([
                'pagination' => ['limit' => AlfaConsentsClient::PAGE_SIZE, 'offset' => $offset, 'totalCount' => \count($this->bankConsents)],
                'items' => \array_slice($this->bankConsents, $offset, AlfaConsentsClient::PAGE_SIZE),
            ], \JSON_THROW_ON_ERROR));
        });
        $clock = new MockClock(self::TODAY);
        $logger = new Logger('test', [$this->logs]);

        return new SyncAlfaConsentsCommand(
            $config,
            new AlfaConsentsClient(new OAuthTokenEndpointClient($http, $clock, $logger), $http, $logger),
            self::getContainer()->get(BankConnectionRepository::class),
            new ConsentExpiryPolicy(),
            new BankConnectionTokenLock($this->em->getConnection(), static function (int $microseconds): void {
            }),
            $this->em,
            $clock,
            $logger,
        );
    }

    private function sandboxConfig(): AlfaIdConfig
    {
        $certificate = $this->files->write('alfa-id.pem', (new TestIdTokenSigner())->certificatePem);

        return new AlfaIdConfig(self::CLIENT_ID, self::CLIENT_SECRET, $certificate, null, null, null);
    }

    private function connection(?string $subject): BankConnection
    {
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withBaseUrl(self::SANDBOX)
            ->withCredentials(self::getContainer()->get(BankCredentialsCodec::class)->encryptStaticToken('token'))
            ->build();
        $connection->recordAuthorizedSubject($subject);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function consent(string $subject, bool $isActive, string $expireDate): array
    {
        return [
            'createDateTime' => '2025-10-27T12:15:10',
            'sub' => $subject,
            'organizationIdHash' => hash('sha256', 'org-'.$subject),
            'clientId' => self::CLIENT_ID,
            'isActive' => $isActive,
            'expireDate' => $expireDate,
        ];
    }

    private function reload(BankConnection $connection): BankConnection
    {
        $this->em->clear();
        $fresh = $this->em->find(BankConnection::class, $connection->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }

    /**
     * @return array<mixed>
     */
    private function warningContext(): array
    {
        foreach ($this->logs->getRecords() as $record) {
            if (str_contains($record->message, 'Alfa consents sync: consents expiring')) {
                return $record->context;
            }
        }
        self::fail('aggregated warning expected');
    }

    private function assertNoPersonalDataLeaked(string $display): void
    {
        $logged = json_encode(array_map(static fn ($record): array => [$record->message, $record->context], $this->logs->getRecords()), \JSON_THROW_ON_ERROR);
        foreach (['user-far', 'user-soon', hash('sha256', 'org-user-far'), self::CLIENT_SECRET, 'app-access'] as $secret) {
            self::assertStringNotContainsString($secret, $logged);
            self::assertStringNotContainsString($secret, $display);
        }
    }
}
