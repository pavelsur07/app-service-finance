<?php

declare(strict_types=1);

namespace App\Analytics\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/health/live', name: 'api_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/api/health/ready', name: 'api_health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = [
            'postgres' => $this->checkPostgres(),
            'redis_cache' => $this->checkRedisCache(),
        ];

        $failed = in_array(false, $checks, true);
        if ($failed) {
            $payload = [
                'status' => 'fail',
                'checks' => $checks,
            ];

            $this->logger->warning('Health ready check failed', ['checks' => $checks]);

            return $this->json($payload, 503);
        }

        return $this->json([
            'status' => 'ok',
            'checks' => $checks,
        ]);
    }

    private function checkPostgres(): bool
    {
        try {
            $result = $this->connection->fetchOne('SELECT 1');

            return (string) $result === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkRedisCache(): bool
    {
        try {
            $value = $this->cache->get('health.ping', function (ItemInterface $item): string {
                $item->expiresAfter(5);

                return 'pong';
            });

            return $value === 'pong';
        } catch (\Throwable) {
            return false;
        }
    }
}
