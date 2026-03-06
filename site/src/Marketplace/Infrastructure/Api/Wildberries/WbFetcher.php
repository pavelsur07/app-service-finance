<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Api\Wildberries;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Contract\MarketplaceFetcherInterface;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('marketplace.fetcher')]
final readonly class WbFetcher implements MarketplaceFetcherInterface
{
    private const BASE_URL = 'https://statistics-api.wildberries.ru';

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
    ) {
    }

    public function supports(MarketplaceType $type): bool
    {
        return MarketplaceType::WILDBERRIES === $type;
    }

    public function fetch(string $companyId, \DateTimeImmutable $dateFrom): string
    {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::WILDBERRIES);
        $apiKey = $credentials['api_key'] ?? null;

        if (null === $apiKey || '' === $apiKey) {
            throw new \RuntimeException('Wildberries API credentials were not found.');
        }

        $response = $this->httpClient->request('GET', self::BASE_URL.'/api/v5/supplier/reportDetailByPeriod', [
            'headers' => [
                'Authorization' => $apiKey,
            ],
            'query' => [
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $dateFrom->format('Y-m-d'),
                'limit' => 100000,
                'rrdid' => 0,
            ],
        ]);

        return $response->getContent();
    }
}
