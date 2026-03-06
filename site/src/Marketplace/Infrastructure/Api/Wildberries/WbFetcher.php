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
    private const PAGE_SIZE = 100000;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MarketplaceCredentialsQuery $credentialsQuery,
    ) {
    }

    public function supports(MarketplaceType $type): bool
    {
        return MarketplaceType::WILDBERRIES === $type;
    }

    /**
     * @return \Generator<int, array>
     */
    public function fetch(string $companyId, \DateTimeImmutable $dateFrom): \Generator
    {
        $credentials = $this->credentialsQuery->getCredentials($companyId, MarketplaceType::WILDBERRIES);
        $apiKey = $credentials['api_key'] ?? null;

        if (null === $apiKey || '' === $apiKey) {
            throw new \RuntimeException('Wildberries API credentials were not found.');
        }

        $rrdid = 0;

        do {
            $response = $this->httpClient->request('GET', self::BASE_URL.'/api/v5/supplier/reportDetailByPeriod', [
                'headers' => [
                    'Authorization' => $apiKey,
                ],
                'query' => [
                    'dateFrom' => $dateFrom->format('Y-m-d'),
                    'dateTo' => $dateFrom->format('Y-m-d'),
                    'limit' => self::PAGE_SIZE,
                    'rrdid' => $rrdid,
                ],
                'timeout' => 120,
            ]);

            $payload = $response->getContent();
            $decoded = json_decode($payload, true);

            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf('Wildberries API returned invalid JSON. Payload: %s', substr($payload, 0, 200)));
            }

            if ([] === $decoded) {
                break;
            }

            if (!array_is_list($decoded)) {
                throw new \RuntimeException(sprintf('Wildberries API returned an error or unexpected format. Payload: %s', substr($payload, 0, 200)));
            }

            yield $decoded;

            $lastRow = end($decoded);
            $lastRrdid = is_array($lastRow) ? (int) ($lastRow['rrd_id'] ?? $lastRow['rrdid'] ?? 0) : 0;

            if ($lastRrdid <= $rrdid) {
                break;
            }

            $rrdid = $lastRrdid;
        } while (count($decoded) >= self::PAGE_SIZE);
    }
}
