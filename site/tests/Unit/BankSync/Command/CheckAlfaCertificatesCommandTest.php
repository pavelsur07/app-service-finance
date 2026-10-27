<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Command;

use App\BankSync\Command\CheckAlfaCertificatesCommand;
use App\BankSync\Domain\Certificate\CertificateExpiryPolicy;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaIdConfig;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use App\Tests\Support\BankSync\TestCertificates;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckAlfaCertificatesCommandTest extends TestCase
{
    private const REPOSITORY_CA_BUNDLE = __DIR__.'/../../../../config/bank_sync/alfa/ca-bundle.pem';

    private static TestCertificates $certificates;
    /** @var array{cert: string, key: string, certPem: string} */
    private static array $client;
    /** @var array{cert: string, key: string, certPem: string} */
    private static array $ca;
    private static string $caBundle;

    private TestHandler $logs;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = new TestCertificates();
        self::$client = self::$certificates->issue('Иванов Иван Иванович', 100);
        self::$ca = self::$certificates->issue('Test Bank Root CA', 1000);
        self::$caBundle = self::$certificates->write('ca-bundle.pem', self::$ca['certPem']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$certificates->remove();
    }

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    public function testFarExpiryIsGreen(): void
    {
        $tester = $this->runCheck(self::$client['cert'], self::$caBundle, 40);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Checked 2 certificate(s): 2 ok, 0 warning, 0 error.', $tester->getDisplay());
        self::assertFalse($this->logs->hasWarningRecords());
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testThirtyDaysLeftWarnsWithoutFailing(): void
    {
        $tester = $this->runCheck(self::$client['cert'], self::$caBundle, 30);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('[warning] client #1', $tester->getDisplay());
        self::assertTrue($this->logs->hasWarningThatContains('expiring within 30 days'));
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testFourteenDaysLeftFailsWithOneError(): void
    {
        $tester = $this->runCheck(self::$client['cert'], self::$caBundle, 14);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[error] client #1', $tester->getDisplay());
        self::assertCount(1, $this->errors());
    }

    public function testExpiredClientCertificateFails(): void
    {
        $tester = $this->runCheck(self::$client['cert'], self::$caBundle, -1);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('-1 days left', $tester->getDisplay());
    }

    public function testExpiringCaFails(): void
    {
        // CA выпущен на 1000 дней, клиентский — на 100: момент за 10 дней до конца CA.
        $tester = $this->runCheck('', self::$caBundle, 10 - 900);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[error] CA #1 Test Bank Root CA', $tester->getDisplay());
    }

    public function testChainInClientFileIsCheckedToo(): void
    {
        $chain = self::$certificates->write('client-chain.crt', self::$client['certPem'].self::$ca['certPem']);

        $tester = $this->runCheck($chain, self::$caBundle, 40);

        self::assertStringContainsString('Checked 3 certificate(s)', $tester->getDisplay());
        self::assertStringContainsString('client #2', $tester->getDisplay());
    }

    public function testTruncatedChainInClientFileFails(): void
    {
        $chain = self::$certificates->write('client-truncated.crt', self::$client['certPem']."-----BEGIN CERTIFICATE-----\nMIIBkTCB+wIJAKHBfpegPj\n");

        $tester = $this->runCheck($chain, self::$caBundle, 40);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('client: file is not readable or not a complete PEM certificate chain', $tester->getDisplay());
    }

    public function testClientSubjectIsNotPrinted(): void
    {
        $tester = $this->runCheck(self::$client['cert'], self::$caBundle, 14);

        // В CN клиентского сертификата — ФИО представителя компании.
        self::assertStringNotContainsString('Иванов', $tester->getDisplay());
        self::assertStringNotContainsString('Иванов', json_encode($this->errors()[0]->context, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('client #1 serial ', $tester->getDisplay());
    }

    public function testNotConfiguredClientIsReportedAndCaStillChecked(): void
    {
        $tester = $this->runCheck('', self::$caBundle, 40);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('client: not configured', $tester->getDisplay());
        self::assertStringContainsString('Checked 1 certificate(s)', $tester->getDisplay());
    }

    public function testConfiguredButUnreadableClientCertificateFails(): void
    {
        $tester = $this->runCheck(self::$client['cert'].'.missing', self::$caBundle, 40);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('client: file is not readable or not a complete PEM certificate chain', $tester->getDisplay());
        self::assertCount(1, $this->errors());
    }

    public function testIdTokenSigningCertificateIsChecked(): void
    {
        // Клиентский сертификат канала не задан; сертификат подписи Alfa ID прода истекает.
        $tester = $this->runCheck('', self::$caBundle, 10, self::$client['cert']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('id-signing-sandbox: not configured, not checked', $tester->getDisplay());
        self::assertStringContainsString('[error] id-signing-production #1', $tester->getDisplay());
        self::assertCount(1, $this->errors());
    }

    public function testRepositoryCaBundleIsGreenToday(): void
    {
        $command = new CheckAlfaCertificatesCommand(
            new AlfaTlsConfig('', '', '', self::REPOSITORY_CA_BUNDLE),
            new AlfaIdConfig(null, null, null, null, null, null),
            new CertificateExpiryPolicy(),
            new MockClock(),
            new Logger('test', [$this->logs]),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Checked 2 certificate(s): 2 ok', $tester->getDisplay());
    }

    /**
     * Запуск на момент, когда до конца клиентского сертификата `$clientDaysLeft` полных суток.
     */
    private function runCheck(string $clientCertPath, string $caBundlePath, int $clientDaysLeft, ?string $prodSigningCertPath = null): CommandTester
    {
        $parsed = openssl_x509_parse(self::$client['certPem']);
        self::assertIsArray($parsed);
        $notAfter = (new \DateTimeImmutable())->setTimestamp((int) $parsed['validTo_time_t']);
        $now = $notAfter->modify(sprintf('%+d days', -$clientDaysLeft))->modify('-1 hour');

        $command = new CheckAlfaCertificatesCommand(
            new AlfaTlsConfig($clientCertPath, '', '', $caBundlePath),
            new AlfaIdConfig(null, null, null, null, null, $prodSigningCertPath),
            new CertificateExpiryPolicy(),
            new MockClock($now),
            new Logger('test', [$this->logs]),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private function errors(): array
    {
        return array_values(array_filter($this->logs->getRecords(), static fn ($record): bool => 'ERROR' === $record->level->getName()));
    }
}
