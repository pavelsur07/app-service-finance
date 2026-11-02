<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing;

use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use App\Billing\Infrastructure\YooKassa\CreatePaymentRequest;
use App\Billing\Infrastructure\YooKassa\YooKassaAmount;
use App\Billing\Infrastructure\YooKassa\YooKassaClient;
use App\Billing\Infrastructure\YooKassa\YooKassaConfig;
use App\Billing\Infrastructure\YooKassa\YooKassaPayment;
use App\Tests\Support\Billing\YooKassaResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YooKassaClientTest extends TestCase
{
    private const CHECKOUT_ID = '01930000-0000-7000-8000-00000000c001';
    private const COMPANY_ID = '01930000-0000-7000-8000-00000000c002';

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'целые рубли' => [497000, '4970.00'];
        yield 'копейки' => [1416005, '14160.05'];
        yield 'меньше рубля' => [7, '0.07'];
        yield 'ноль' => [0, '0.00'];
    }

    #[DataProvider('amounts')]
    public function testAmountRoundTripWithoutFloat(int $kopecks, string $value): void
    {
        self::assertSame($value, YooKassaAmount::format($kopecks));
        self::assertSame($kopecks, YooKassaAmount::parse($value));
    }

    public function testAmountParseAcceptsShortFractionAndRejectsGarbage(): void
    {
        self::assertSame(497000, YooKassaAmount::parse('4970'));
        self::assertSame(497050, YooKassaAmount::parse('4970.5'));
        self::assertNull(YooKassaAmount::parse('4970,00'));
        self::assertNull(YooKassaAmount::parse('-1.00'));
        self::assertNull(YooKassaAmount::parse('1.005'));
        self::assertNull(YooKassaAmount::parse(4970.0));
    }

    public function testCreatePaymentSendsIdempotenceKeyAuthMetadataAndNoReceiptWithoutVatCode(): void
    {
        $http = new MockHttpClient();
        $responses = YooKassaResponses::install($http)->then(YooKassaResponses::payment('pending', 497000, $this->metadata()));
        $logger = $this->logger();

        $payment = $this->client($http, $logger)->createPayment($this->request());

        self::assertSame(YooKassaResponses::PAYMENT_ID, $payment->id);
        self::assertSame(YooKassaPayment::STATUS_PENDING, $payment->status);
        self::assertSame(497000, $payment->amountKopecks);
        self::assertStringStartsWith('https://yoomoney.ru/checkout/', (string) $payment->confirmationUrl);
        self::assertSame($this->metadata(), $payment->metadata);

        self::assertCount(1, $responses->requests);
        $request = $responses->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.yookassa.ru/v3/payments', $request['url']);
        $headers = implode("\n", $request['options']['headers'] ?? []);
        self::assertStringContainsString('Idempotence-Key: '.self::CHECKOUT_ID, $headers);
        self::assertStringContainsString('Authorization: Basic '.base64_encode('123456:secret_key'), $headers);

        $body = json_decode((string) $request['options']['body'], true);
        self::assertIsArray($body);
        self::assertSame(['value' => '4970.00', 'currency' => 'RUB'], $body['amount']);
        self::assertTrue($body['capture']);
        self::assertSame(['type' => 'redirect', 'return_url' => 'https://app.test/company/price?checkout='.self::CHECKOUT_ID], $body['confirmation']);
        self::assertSame($this->metadata(), $body['metadata']);
        self::assertArrayNotHasKey('receipt', $body);

        $logged = json_encode($logger->records, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('secret_key', (string) $logged);
        self::assertStringNotContainsString('owner@example.test', (string) $logged);
        self::assertStringNotContainsString(YooKassaResponses::PAYMENT_ID, (string) $logged, 'в лог — адрес без ID платежа и без тела ответа');
    }

    public function testReceiptIsSentWhenVatCodeIsConfigured(): void
    {
        $http = new MockHttpClient();
        $responses = YooKassaResponses::install($http)->then(YooKassaResponses::payment('pending', 497000, $this->metadata()));

        $this->client($http, $this->logger(), vatCode: '1')->createPayment($this->request());

        $body = json_decode((string) $responses->requests[0]['options']['body'], true);
        self::assertIsArray($body);
        self::assertSame(['email' => 'owner@example.test'], $body['receipt']['customer']);
        self::assertSame([[
            'description' => 'Ваш Финдир: тариф «Старт», 1 мес.',
            'quantity' => '1.00',
            'amount' => ['value' => '4970.00', 'currency' => 'RUB'],
            'vat_code' => 1,
            'payment_subject' => 'service',
            'payment_mode' => 'full_payment',
        ]], $body['receipt']['items']);
    }

    public function testGetPaymentParsesSucceededPayment(): void
    {
        $http = new MockHttpClient();
        $responses = YooKassaResponses::install($http)->then(YooKassaResponses::payment('succeeded', 1416000, $this->metadata()));

        $payment = $this->client($http, $this->logger())->getPayment(YooKassaResponses::PAYMENT_ID);

        self::assertSame('GET', $responses->requests[0]['method']);
        self::assertSame('https://api.yookassa.ru/v3/payments/'.YooKassaResponses::PAYMENT_ID, $responses->requests[0]['url']);
        self::assertSame(YooKassaPayment::STATUS_SUCCEEDED, $payment->status);
        self::assertTrue($payment->paid);
        self::assertSame(1416000, $payment->amountKopecks);
        self::assertNull($payment->confirmationUrl);
        self::assertSame('2027-03-10T07:05:00+00:00', $payment->capturedAt?->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{MockResponse, class-string<\Throwable>}>
     */
    public static function failures(): iterable
    {
        yield '500' => [YooKassaResponses::error(500), YooKassaUnavailableException::class];
        yield '429' => [YooKassaResponses::error(429, 'too_many_requests'), YooKassaUnavailableException::class];
        yield '202 в обработке' => [new MockResponse('{"type":"processing","retry_after":1800}', ['http_code' => 202]), YooKassaUnavailableException::class];
        yield 'сеть' => [new MockResponse('', ['error' => 'Connection refused']), YooKassaUnavailableException::class];
        yield '401 неверный ключ' => [YooKassaResponses::error(401, 'invalid_credentials'), YooKassaRequestFailedException::class];
        yield '400' => [YooKassaResponses::error(400, 'invalid_request'), YooKassaRequestFailedException::class];
        yield 'не JSON' => [new MockResponse('<html>', ['http_code' => 200]), YooKassaRequestFailedException::class];
        yield 'не платёж' => [new MockResponse('{"id":"x"}', ['http_code' => 200]), YooKassaRequestFailedException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('failures')]
    public function testFailuresAreClassified(MockResponse $response, string $expected): void
    {
        $http = new MockHttpClient();
        YooKassaResponses::install($http)->then($response);

        $this->expectException($expected);
        $this->client($http, $this->logger())->getPayment(YooKassaResponses::PAYMENT_ID);
    }

    public function testGetPaymentRejectsMalformedIdWithoutRequest(): void
    {
        $http = new MockHttpClient();
        $responses = YooKassaResponses::install($http);

        try {
            $this->client($http, $this->logger())->getPayment('../refunds');
            self::fail('ожидался отказ');
        } catch (YooKassaRequestFailedException) {
        }
        self::assertSame([], $responses->requests);
    }

    public function testConfigIsDisabledWithoutKeysAndVatCodeIsValidated(): void
    {
        self::assertFalse((new YooKassaConfig('', ''))->isEnabled());
        self::assertFalse((new YooKassaConfig('123456', ' '))->isEnabled());
        self::assertTrue((new YooKassaConfig('123456', 'live_x'))->isEnabled());
        self::assertNull((new YooKassaConfig('1', 'x', ''))->vatCode());
        self::assertNull((new YooKassaConfig('1', 'x', 'abc'))->vatCode());
        self::assertSame(4, (new YooKassaConfig('1', 'x', '4'))->vatCode());
    }

    private function client(MockHttpClient $http, YooKassaRecordingLogger $logger, string $vatCode = ''): YooKassaClient
    {
        return new YooKassaClient($http, new YooKassaConfig('123456', 'secret_key', $vatCode), $logger);
    }

    private function request(): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            self::CHECKOUT_ID,
            497000,
            'Ваш Финдир: тариф «Старт», 1 мес.',
            'https://app.test/company/price?checkout='.self::CHECKOUT_ID,
            $this->metadata(),
            'owner@example.test',
        );
    }

    /**
     * @return array<string, string>
     */
    private function metadata(): array
    {
        return ['checkout_id' => self::CHECKOUT_ID, 'company_id' => self::COMPANY_ID];
    }

    private function logger(): YooKassaRecordingLogger
    {
        return new YooKassaRecordingLogger();
    }
}

final class YooKassaRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
