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

    public function fetch(string $companyId, \DateTimeImmutable $dateFrom): string
    {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::OZON);
        $apiKey = $credentials['api_key'] ?? null;
        $clientId = $credentials['client_id'] ?? null;

        if (null === $apiKey || '' === $apiKey || null === $clientId || '' === $clientId) {
            throw new \RuntimeException('Ozon API credentials were not found.');
        }

        $response = $this->httpClient->request('POST', self::BASE_URL.'/v2/finance/transaction/list', [
            'headers' => [
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'filter' => [
                    'date' => [
                        'from' => $dateFrom->format('Y-m-d\T00:00:00\Z'),
                        'to' => $dateFrom->format('Y-m-d\T23:59:59\Z'),
                    ],
                ],
                'page' => 1,
                'page_size' => 1000,
            ],
        ]);

        return $response->getContent();
    }
}
