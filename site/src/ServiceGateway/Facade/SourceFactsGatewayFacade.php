<?php

declare(strict_types=1);

namespace App\ServiceGateway\Facade;

use App\Marketplace\Facade\MarketplaceFacade;
use App\ServiceGateway\Application\DTO\SourceFactBatch;
use App\ServiceGateway\Application\DTO\SourceFactBatchesPage;
use App\ServiceGateway\Application\DTO\SourceFactPage;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\ServiceGateway\Infrastructure\Client\SourceFactsClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SourceFactsGatewayFacade implements SourceFactsGatewayFacadeInterface
{
    /** Ресурсы, которые сервисы публикуют фактами (белые списки сервисов — `facts.Resources`). */
    private const RESOURCES = [
        'ozon-fetcher' => ['ozon.finance.accrual-by-day', 'ozon.ads.statistics'],
        'wb-fetcher' => ['wb.finance.sales-report.daily', 'wb.ads.daily'],
    ];

    /** @var array<string, SourceFactsClientInterface> сервис → клиент */
    private array $clients;

    /**
     * @param iterable<SourceFactsClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.source_facts_client')]
        iterable $clients,
        private MarketplaceFacade $marketplace,
    ) {
        $map = [];
        foreach ($clients as $client) {
            $map[$client->service()] = $client;
        }
        $this->clients = $map;
    }

    public function sources(): array
    {
        $sources = [];
        foreach ($this->clients as $service => $client) {
            if ($client->isEnabled() && isset(self::RESOURCES[$service])) {
                $sources[$service] = self::RESOURCES[$service];
            }
        }

        return $sources;
    }

    public function batches(string $service, string $resourceType, ?string $after, int $limit): SourceFactBatchesPage
    {
        $page = $this->client($service)->fetchBatches($resourceType, $after, $limit);
        if ([] === $page->batches) {
            return $page;
        }
        // connection_ref — id подключения; компанию из ответа сервиса на веру не берём.
        $companies = [];
        foreach ([...$this->marketplace->getActiveOzonSellerConnections(), ...$this->marketplace->getActiveWbSellerConnections()] as $connection) {
            $companies[strtolower($connection['connectionId'])] = strtolower($connection['companyId']);
        }

        return new SourceFactBatchesPage(
            array_map(
                static fn (SourceFactBatch $b): SourceFactBatch => $b->withCompanyId($companies[strtolower($b->connectionRef)] ?? null),
                $page->batches,
            ),
            $page->nextCursor,
        );
    }

    public function page(string $service, string $batchId, int $page): SourceFactPage
    {
        return $this->client($service)->fetchPage($batchId, $page);
    }

    public function ack(string $service, array $batchIds): int
    {
        return $this->client($service)->ack($batchIds);
    }

    private function client(string $service): SourceFactsClientInterface
    {
        return $this->clients[$service] ?? throw ServiceUnavailableException::notConfigured($service);
    }
}
