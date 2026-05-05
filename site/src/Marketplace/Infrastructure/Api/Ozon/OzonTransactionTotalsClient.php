<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final readonly class OzonTransactionTotalsClient
{
    private const URL = 'https://api-seller.ozon.ru/v3/finance/transaction/totals';

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
    ) {
    }

    /**
     * @return array{
     *     accruals_for_sale: string,
     *     sale_commission: string,
     *     processing_and_delivery: string,
     *     services_amount: string,
     *     refunds_and_cancellations: string,
     *     compensation_amount: string,
     *     money_transfer: string,
     *     others_amount: string,
     * }
     */
    public function fetchTotals(
        string $companyId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        Assert::uuid($companyId);

        if ($from > $to) {
            throw new \InvalidArgumentException('Дата начала периода не может быть позже даты окончания.');
        }

        $credentials = $this->credentialsQuery->getCredentials(
            $companyId,
            MarketplaceType::OZON,
            MarketplaceConnectionType::SELLER,
        );

        if (null === $credentials) {
            throw new \RuntimeException('Ozon Seller credentials не найдены для компании.');
        }

        $apiKey = $credentials['api_key'];
        $clientId = $credentials['client_id'] ?? null;

        if ('' === $apiKey || null === $clientId || '' === $clientId) {
            throw new \RuntimeException('Ozon Seller credentials неполные: отсутствует api_key или client_id.');
        }

        $response = $this->httpClient->request('POST', self::URL, [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'date' => [
                    'from' => $from->format('Y-m-d\T00:00:00.000\Z'),
                    'to' => $to->format('Y-m-d\T23:59:59.000\Z'),
                ],
                'transaction_type' => 'all',
                'posting_number' => '',
            ],
        ]);

        $payload = $response->toArray(false);
        $result = $payload['result'] ?? null;

        if (!is_array($result)) {
            $preview = mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'invalid_response', 0, 300);
            throw new \RuntimeException(sprintf('Некорректный ответ Ozon transaction totals: result отсутствует или не является массивом. preview=%s', $preview));
        }

        return [
            'accruals_for_sale' => $this->decimalField($result, 'accruals_for_sale'),
            'sale_commission' => $this->decimalField($result, 'sale_commission'),
            'processing_and_delivery' => $this->decimalField($result, 'processing_and_delivery'),
            'services_amount' => $this->decimalField($result, 'services_amount'),
            'refunds_and_cancellations' => $this->decimalField($result, 'refunds_and_cancellations'),
            'compensation_amount' => $this->decimalField($result, 'compensation_amount'),
            'money_transfer' => $this->decimalField($result, 'money_transfer'),
            'others_amount' => $this->decimalField($result, 'others_amount'),
        ];
    }

    private function decimalField(array $result, string $field): string
    {
        if (!array_key_exists($field, $result)) {
            return '0.00';
        }

        $value = $result[$field];

        if (null === $value || '' === $value) {
            return '0.00';
        }

        return $this->decimalString($value, $field);
    }

    private function decimalString(mixed $value, string $field): string
    {
        if (is_int($value)) {
            return sprintf('%d.00', $value);
        }

        if (is_float($value)) {
            $value = sprintf('%.6F', $value);
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            if (!preg_match('/^-?\d+(?:\.\d+)?$/', $normalized)) {
                throw new \RuntimeException(sprintf(
                    'Некорректное числовое значение Ozon transaction totals в поле %s.',
                    $field,
                ));
            }

            $negative = str_starts_with($normalized, '-');
            if ($negative) {
                $normalized = substr($normalized, 1);
            }

            [$intPart, $fractionPart] = array_pad(explode('.', $normalized, 2), 2, '');
            $fractionPart = preg_replace('/\D/', '', $fractionPart) ?? '';
            $fractionPart = str_pad($fractionPart, 3, '0');

            $hundredths = (int) substr($fractionPart, 0, 2);
            $thousandth = (int) $fractionPart[2];

            if ($thousandth >= 5) {
                ++$hundredths;
            }

            $intValue = (int) $intPart;
            if ($hundredths >= 100) {
                ++$intValue;
                $hundredths = 0;
            }

            $result = sprintf('%d.%02d', $intValue, $hundredths);

            return $negative && '0.00' !== $result ? '-' . $result : $result;
        }

        throw new \RuntimeException(sprintf(
            'Некорректное числовое значение Ozon transaction totals в поле %s.',
            $field,
        ));
    }
}
