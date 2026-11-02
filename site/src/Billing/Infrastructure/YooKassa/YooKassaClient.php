<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\YooKassa;

use App\Billing\Exception\YooKassaRequestFailedException;
use App\Billing\Exception\YooKassaUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ЮKassa API v3: создание платежа (одностадийного, `capture: true`, redirect на страницу оплаты)
 * и чтение платежа. Авторизация — Basic `shopId:secretKey`.
 *
 * В лог — метод, адрес без ID платежа, статус, время, код ошибки ЮKassa. Ни тело запроса
 * (e-mail покупателя), ни тело ответа, ни ключи не логируются; исключения HttpClient не цепляются.
 */
final readonly class YooKassaClient
{
    /** Простой соединения. */
    private const TIMEOUT_SECONDS = 10;
    /** Весь запрос целиком: синхронная проверка идёт и в GET страницы тарифа. */
    private const MAX_DURATION_SECONDS = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private YooKassaConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws YooKassaUnavailableException
     * @throws YooKassaRequestFailedException
     */
    public function createPayment(CreatePaymentRequest $request): YooKassaPayment
    {
        $amount = ['value' => YooKassaAmount::format($request->amountKopecks), 'currency' => 'RUB'];
        $body = [
            'amount' => $amount,
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $request->returnUrl],
            'description' => mb_substr($request->description, 0, 128),
            'metadata' => $request->metadata,
        ];

        $vatCode = $this->config->vatCode();
        if (null !== $vatCode && null !== $request->customerEmail && '' !== $request->customerEmail) {
            $body['receipt'] = [
                'customer' => ['email' => $request->customerEmail],
                'items' => [[
                    'description' => mb_substr($request->description, 0, 128),
                    'quantity' => '1.00',
                    'amount' => $amount,
                    'vat_code' => $vatCode,
                    'payment_subject' => 'service',
                    'payment_mode' => 'full_payment',
                ]],
            ];
        }

        return $this->send('POST', '/payments', '/payments', [
            'json' => $body,
            'headers' => ['Idempotence-Key' => $request->idempotenceKey],
        ]);
    }

    /**
     * @throws YooKassaUnavailableException
     * @throws YooKassaRequestFailedException
     */
    public function getPayment(string $paymentId): YooKassaPayment
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $paymentId)) {
            throw new YooKassaRequestFailedException(null, 'invalid_payment_id');
        }

        return $this->send('GET', '/payments/'.$paymentId, '/payments/{id}', []);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, string $logPath, array $options): YooKassaPayment
    {
        $startedAt = microtime(true);
        $context = ['method' => $method, 'url' => rtrim($this->config->apiUrl, '/').$logPath];

        try {
            $response = $this->httpClient->request($method, rtrim($this->config->apiUrl, '/').$path, $options + [
                'auth_basic' => [$this->config->shopId, $this->config->secretKey],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $statusCode = $response->getStatusCode();
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('YooKassa: запрос не выполнен — сеть', $context + [
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception_class' => $exception::class,
            ]);

            throw new YooKassaUnavailableException(null);
        } catch (DecodingExceptionInterface) {
            $payload = null;
        }

        $context['status_code'] = $statusCode;
        $context['duration_ms'] = $this->elapsedMs($startedAt);

        if (200 !== $statusCode) {
            $errorCode = \is_array($payload) && \is_string($payload['code'] ?? null) ? $payload['code'] : null;
            $context['error_code'] = $errorCode ?? 'none';
            // 202 — ЮKassa ещё обрабатывает запрос с этим Idempotence-Key: повторить позже.
            if (\in_array($statusCode, [202, 408, 429], true) || $statusCode >= 500) {
                $this->logger->warning('YooKassa: временно недоступна', $context);

                throw new YooKassaUnavailableException($statusCode);
            }

            $this->logger->error('YooKassa: запрос отклонён', $context);

            throw new YooKassaRequestFailedException($statusCode, $errorCode);
        }

        $payment = \is_array($payload) ? YooKassaPayment::fromArray($payload) : null;
        if (null === $payment) {
            $this->logger->error('YooKassa: нечитаемый ответ', $context);

            throw new YooKassaRequestFailedException(null);
        }

        $this->logger->info('YooKassa: запрос выполнен', $context + ['payment_status' => $payment->status]);

        return $payment;
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
