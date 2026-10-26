<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Command;

use App\BankSync\Command\ProbeAlfaChannelCommand;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaTlsConfig;
use App\Tests\Support\BankSync\TestCertificates;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ProbeAlfaChannelCommandTest extends TestCase
{
    private static TestCertificates $certificates;
    /** @var array{cert: string, key: string, certPem: string} */
    private static array $client;
    private static string $caBundle;

    public static function setUpBeforeClass(): void
    {
        self::$certificates = new TestCertificates();
        self::$client = self::$certificates->issue('client', 365);
        self::$caBundle = self::$certificates->write('ca-bundle.pem', self::$client['certPem']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$certificates->remove();
    }

    public function testAnyBankAnswerMeansTlsPassedAndNoTokenIsSent(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'headers' => $options['headers']];

            return new MockResponse('{"error":"unauthorized"}', ['http_code' => 401, 'response_headers' => ['X-TraceId' => 'trace-1']]);
        });

        $tester = $this->runProbe($client, ['--target' => 'sandbox']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('OK: bank answered HTTP 401, X-TraceId trace-1', $tester->getDisplay());
        self::assertSame('https://sandbox.alfabank.ru/api/jp/open-banking/v1.3/accounts', $requests[0]['url']);
        self::assertEmpty(array_filter($requests[0]['headers'], static fn (string $header): bool => str_starts_with(strtolower($header), 'authorization')));
    }

    public function testHandshakeFailureFails(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'tlsv13 alert certificate required']));

        $tester = $this->runProbe($client, ['--target' => 'production']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('TLS channel to https://baas.alfabank.ru FAILED: bank requires a client certificate', $tester->getDisplay());
    }

    public function testTransportMessageWithLocalPathsIsNotPrinted(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'unable to set private key file: \'/secret/dir/client.key\' type PEM']));

        $tester = $this->runProbe($client, ['--target' => 'sandbox']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('client key cannot be loaded', $tester->getDisplay());
        self::assertStringNotContainsString('/secret/dir', $tester->getDisplay());
    }

    public function testNotConfiguredChannelFailsWithoutRequest(): void
    {
        $client = new MockHttpClient([]);

        $tester = new CommandTester(new ProbeAlfaChannelCommand(new AlfaTlsConfig('', '', '', self::$caBundle), $client));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ALFA_TLS_CERT_PATH is not set', $tester->getDisplay());
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testUnknownTargetIsRejectedWithoutRequest(): void
    {
        $client = new MockHttpClient([]);

        $tester = $this->runProbe($client, ['--target' => 'staging']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, $client->getRequestsCount());
    }

    /**
     * @param array<string, string> $input
     */
    private function runProbe(HttpClientInterface $client, array $input): CommandTester
    {
        $tester = new CommandTester(new ProbeAlfaChannelCommand(
            new AlfaTlsConfig(self::$client['cert'], self::$client['key'], '', self::$caBundle),
            $client,
        ));
        $tester->execute($input);

        return $tester;
    }
}
