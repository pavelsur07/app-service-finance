<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaHttpClientFactory;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use App\Tests\Support\BankSync\TestCertificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

final class AlfaTlsConfigTest extends TestCase
{
    private const PASSPHRASE = 'test-key-passphrase';
    private const CA_BUNDLE = __DIR__.'/../../../../../../config/bank_sync/alfa/ca-bundle.pem';

    private static TestCertificates $certificates;
    /** @var array{cert: string, key: string, certPem: string} */
    private static array $client;
    /** @var array{cert: string, key: string, certPem: string} */
    private static array $other;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = new TestCertificates();
        self::$client = self::$certificates->issue('client', 365, self::PASSPHRASE);
        self::$other = self::$certificates->issue('other', 365);
    }

    public static function tearDownAfterClass(): void
    {
        self::$certificates->remove();
    }

    public function testOptionsCarryClientCertificateKeyAndOnlyBankCa(): void
    {
        $options = $this->config(self::$client['cert'], self::$client['key'], self::PASSPHRASE)->httpOptions();

        self::assertSame(self::$client['cert'], $options['local_cert']);
        self::assertSame(self::$client['key'], $options['local_pk']);
        self::assertSame(self::PASSPHRASE, $options['passphrase']);
        self::assertSame(self::CA_BUNDLE, $options['cafile']);
        self::assertSame(\dirname(self::CA_BUNDLE), $options['capath'], 'без capath curl доверял бы и системному хранилищу');
        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_host']);
        self::assertSame(\STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $options['crypto_method']);
    }

    public function testUnencryptedKeyNeedsNoPassphrase(): void
    {
        $options = $this->config(self::$other['cert'], self::$other['key'], '')->httpOptions();

        self::assertArrayNotHasKey('passphrase', $options);
    }

    public function testFactoryClientUsesTheseOptionsAndDefaultClientDoesNot(): void
    {
        $alfa = (new AlfaHttpClientFactory($this->config(self::$client['cert'], self::$client['key'], self::PASSPHRASE)))->create();

        self::assertSame(self::$client['cert'], $this->defaultOptions($alfa)['local_cert']);
        self::assertSame(self::CA_BUNDLE, $this->defaultOptions($alfa)['cafile']);
        self::assertNull($this->defaultOptions(HttpClient::create())['local_cert']);
        self::assertNull($this->defaultOptions(HttpClient::create())['cafile']);
    }

    public function testRepositoryCaBundleHoldsOnlyCertificates(): void
    {
        $pem = (string) file_get_contents(self::CA_BUNDLE);

        self::assertSame(2, preg_match_all('/-----BEGIN CERTIFICATE-----/', $pem));
        self::assertStringNotContainsString('PRIVATE KEY', $pem);
    }

    public function testClientCertificatePathIsNullWhenNotConfigured(): void
    {
        self::assertNull($this->config('', '', '')->clientCertificatePath());
        self::assertNull((new AlfaTlsConfig(null, null, null, self::CA_BUNDLE))->clientCertificatePath(), 'переменной нет в окружении');
        self::assertSame(self::$client['cert'], $this->config(self::$client['cert'], '', '')->clientCertificatePath());
    }

    /**
     * @return iterable<string, array{0: \Closure(): array{string, string, string}, 1: string}>
     */
    public static function misconfigurations(): iterable
    {
        yield 'certificate not set' => [static fn (): array => ['', self::$client['key'], self::PASSPHRASE], 'ALFA_TLS_CERT_PATH is not set'];
        yield 'key not set' => [static fn (): array => [self::$client['cert'], ' ', self::PASSPHRASE], 'ALFA_TLS_KEY_PATH is not set'];
        yield 'certificate file missing' => [static fn (): array => [self::$client['cert'].'.missing', self::$client['key'], self::PASSPHRASE], 'file from ALFA_TLS_CERT_PATH is not readable'];
        yield 'certificate file is a key' => [static fn (): array => [self::$other['key'], self::$client['key'], self::PASSPHRASE], 'is not a PEM certificate'];
        yield 'key file missing' => [static fn (): array => [self::$client['cert'], self::$client['key'].'.missing', self::PASSPHRASE], 'file from ALFA_TLS_KEY_PATH is not readable'];
        yield 'wrong passphrase' => [static fn (): array => [self::$client['cert'], self::$client['key'], 'wrong-passphrase'], 'check ALFA_TLS_KEY_PASSPHRASE'];
        yield 'key of another certificate' => [static fn (): array => [self::$client['cert'], self::$other['key'], ''], 'does not match the certificate'];
    }

    /**
     * @param \Closure(): array{string, string, string} $settings
     */
    #[DataProvider('misconfigurations')]
    public function testMisconfigurationIsReportedWithoutPathsOrPassphrase(\Closure $settings, string $problem): void
    {
        [$certPath, $keyPath, $passphrase] = $settings();

        try {
            $this->config($certPath, $keyPath, $passphrase)->httpOptions();
            self::fail('misconfiguration must be reported');
        } catch (BankChannelNotConfiguredException $exception) {
            self::assertStringContainsString($problem, $exception->getMessage());
            foreach ([self::PASSPHRASE, 'wrong-passphrase', \dirname(self::$client['key'])] as $secret) {
                self::assertStringNotContainsString($secret, $exception->getMessage());
            }
            self::assertNull($exception->getPrevious());
        }
    }

    private function config(string $certPath, string $keyPath, string $passphrase): AlfaTlsConfig
    {
        return new AlfaTlsConfig($certPath, $keyPath, $passphrase, self::CA_BUNDLE);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptions(object $client): array
    {
        $options = (new \ReflectionProperty($client, 'defaultOptions'))->getValue($client);
        self::assertIsArray($options);

        return $options;
    }
}
