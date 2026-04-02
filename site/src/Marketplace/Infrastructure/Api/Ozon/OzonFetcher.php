<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Contract\MarketplaceFetcherInterface;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('marketplace.fetcher')]
final readonly class OzonFetcher implements MarketplaceFetcherInterface
{
    private const BASE_URL = 'https://api-seller.ozon.ru';

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
    ) {
    }

    public function supports(MarketplaceType $type): bool
    {
        return MarketplaceType::OZON === $type;
    }

    /**
     * @return \Generator<int, array>
     */
    public function fetch(string $companyId, \DateTimeImmutable $dateFrom): \Generator
    {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::OZON);
        $apiKey      = $credentials['api_key'] ?? null;
        $clientId    = $credentials['client_id'] ?? null;

        if (null === $apiKey || '' === $apiKey || null === $clientId || '' === $clientId) {
            throw new \RuntimeException('Ozon API credentials were not found.');
        }

        $utc  = new \DateTimeZone('UTC');
        $from = $dateFrom->setTime(0, 0, 0)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $to   = $dateFrom->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');

        $page      = 1;
        $pageCount = 1;

        do {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/v2/finance/transaction/list', [
                'headers' => [
                    'Client-Id'    => $clientId,
                    'Api-Key'      => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'filter' => [
                        'date' => [
                            'from' => $from,
                            'to'   => $to,
                        ],
                    ],
                    'page'      => $page,
                    'page_size' => 1000,
                ],
                'timeout' => 120,
            ]);

            $payload = $response->getContent();
            $decoded = json_decode($payload, true);

            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf('Ozon API returned invalid JSON. Payload: %s', substr($payload, 0, 200)));
            }

            if (!isset($decoded['result'])) {
                throw new \RuntimeException(sprintf('Ozon API returned unexpected format. Payload: %s', substr($payload, 0, 200)));
            }

            yield $decoded;

            $pageCount = (int) ($decoded['result']['page_count'] ?? $page);
            $page++;
        } while ($page <= $pageCount);
    }
}
