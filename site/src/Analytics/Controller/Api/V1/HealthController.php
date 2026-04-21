<?php

declare(strict_types=1);

namespace App\Analytics\Controller\Api\V1;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[OA\Tag(name: 'Health')]
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Get(
        summary: 'Liveness probe',
        description: 'Всегда возвращает 200 OK пока процесс жив. Не проверяет внешние зависимости.',
        security: [],
        tags: ['Health']
    )]
    #[OA\Response(
        response: 200,
        description: 'Сервис жив',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
            ],
            type: 'object'
        )
    )]
    #[Route('/api/health/live', name: 'api_health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[OA\Get(
        summary: 'Readiness probe',
        description: 'Проверяет доступность Postgres и Redis. Возвращает 503 при деградации зависимостей.',
        security: [],
        tags: ['Health']
    )]
    #[OA\Response(
        response: 200,
        description: 'Все зависимости доступны',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(
                    property: 'checks',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'postgres', type: 'boolean', example: true),
                        new OA\Property(property: 'redis_cache', type: 'boolean', example: true),
                    ]
                ),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 503,
        description: 'Одна или более зависимостей недоступны',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'fail'),
                new OA\Property(
                    property: 'checks',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'postgres', type: 'boolean', example: false),
                        new OA\Property(property: 'redis_cache', type: 'boolean', example: true),
                    ]
                ),
            ],
            type: 'object'
        )
    )]
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

            return '1' === (string) $result;
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

            return 'pong' === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
