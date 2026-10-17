<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Internal;

use App\Marketplace\Application\Service\MarketplaceConnectionsSnapshotBuilder;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\Internal\ActiveConnectionCredentialsQuery;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Активные кабинеты и ключи для сервисов `services/`
 * (`services/_contracts/marketplace-connections/v1`). Доступ — firewall
 * `internal_api`; ответ несёт секреты, поэтому `no-store` и без логов тела.
 */
final class MarketplaceConnectionsController extends AbstractController
{
    public function __construct(
        private readonly ActiveConnectionCredentialsQuery $query,
        private readonly MarketplaceConnectionsSnapshotBuilder $builder,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/internal/marketplace-connections', name: 'internal_marketplace_connections', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $provider = (string) $request->query->get('provider', MarketplaceConnectionsSnapshotBuilder::PROVIDER);
        if (MarketplaceConnectionsSnapshotBuilder::PROVIDER !== $provider) {
            return $this->json(
                ['error' => ['code' => 'unsupported_provider', 'message' => 'Поддерживается только provider=ozon']],
                422,
                ['Cache-Control' => 'no-store'],
            );
        }

        $snapshot = $this->builder->build($this->query->fetchActive(MarketplaceType::OZON), $this->clock->now());

        $this->logger->info('Internal connections snapshot served', [
            'provider' => $provider,
            'connections' => \count($snapshot->connections),
        ]);

        return $this->json($snapshot->toArray(), 200, ['Cache-Control' => 'no-store']);
    }
}
