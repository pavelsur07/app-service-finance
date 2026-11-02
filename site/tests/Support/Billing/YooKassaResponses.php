<?php

declare(strict_types=1);

namespace App\Tests\Support\Billing;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Ответы ЮKassa API v3 для тестов — по документации «Объект платежа» (yookassa.ru/developers/api#payment_object).
 * Записывает запросы, чтобы тест проверил метод, адрес, заголовки и тело.
 */
final class YooKassaResponses
{
    public const PAYMENT_ID = '2f8a6b3c-000f-5000-9000-1a2b3c4d5e6f';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<MockResponse|\Closure(): MockResponse> */
    private array $queue = [];

    public static function install(MockHttpClient $client): self
    {
        $responses = new self();
        $client->setResponseFactory(static function (string $method, string $url, array $options) use ($responses): MockResponse {
            $responses->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($responses->queue) ?? throw new \LogicException(sprintf('Неожиданный запрос в ЮKassa: %s %s', $method, $url));

            return $next instanceof \Closure ? $next() : $next;
        });

        return $responses;
    }

    public function then(MockResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pendingCount(): int
    {
        return \count($this->queue);
    }

    /**
     * @param array<string, string> $metadata
     */
    public static function payment(string $status, int $amountKopecks, array $metadata, string $id = self::PAYMENT_ID): MockResponse
    {
        $value = sprintf('%d.%02d', intdiv($amountKopecks, 100), $amountKopecks % 100);
        $body = [
            'id' => $id,
            'status' => $status,
            'paid' => 'succeeded' === $status,
            'amount' => ['value' => $value, 'currency' => 'RUB'],
            'created_at' => '2027-03-10T07:00:00.000Z',
            'description' => 'Ваш Финдир',
            'metadata' => $metadata,
            'recipient' => ['account_id' => '123456', 'gateway_id' => '654321'],
            'refundable' => 'succeeded' === $status,
            'test' => true,
        ];
        if ('pending' === $status) {
            $body['confirmation'] = ['type' => 'redirect', 'confirmation_url' => 'https://yoomoney.ru/checkout/payments/v2/contract?orderId='.$id];
        }
        if ('succeeded' === $status) {
            $body['captured_at'] = '2027-03-10T07:05:00.000Z';
            $body['income_amount'] = ['value' => $value, 'currency' => 'RUB'];
            $body['payment_method'] = ['type' => 'bank_card', 'id' => $id, 'saved' => false, 'title' => 'Bank card *4444'];
        }
        if ('canceled' === $status) {
            $body['cancellation_details'] = ['party' => 'yoo_money', 'reason' => 'expired_on_confirmation'];
        }

        return new MockResponse((string) json_encode($body), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public static function error(int $httpCode, string $code = 'internal_server_error'): MockResponse
    {
        return new MockResponse((string) json_encode([
            'type' => 'error',
            'id' => 'ab768976-cc6f-4f0e-9a39-000000000000',
            'code' => $code,
            'description' => 'Ошибка',
        ]), ['http_code' => $httpCode, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
