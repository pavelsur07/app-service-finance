<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Doctrine\DBAL\Connection;
use Predis\Client as PredisClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/_health', name: 'app_health_check', methods: ['GET'])]
    public function check(
        Request $request,
        Connection $connection,
        PredisClient $redisClient,
        LoggerInterface $logger
    ): JsonResponse {
        // 1. ЗАЩИТА ОТ БОТОВ: Проверяем секретный токен
        // Используем getenv() — это самый надежный способ в Docker/FPM
        $expectedToken = getenv('HEALTH_CHECK_TOKEN') ?: ($_ENV['HEALTH_CHECK_TOKEN'] ?? $_SERVER['HEALTH_CHECK_TOKEN'] ?? null);
        $providedToken = $request->query->get('token');

        if (!$expectedToken || !hash_equals($expectedToken, (string) $providedToken)) {
            // Отдаем 403 Forbidden и не нагружаем инфраструктуру
            return $this->json(['status' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        // 2. ИНИЦИАЛИЗАЦИЯ
        $status = 'ok';
        $httpCode = Response::HTTP_OK;
        $components = [
            'database' => 'ok',
            'redis'    => 'ok',
        ];

        // 3. ПРОВЕРКА POSTGRESQL
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            $components['database'] = 'error';
            $status = 'error';
            $httpCode = Response::HTTP_SERVICE_UNAVAILABLE;

            $logger->error('HealthCheck: Database is down', ['exception' => $exception]);
        }

        // 4. ПРОВЕРКА REDIS
        try {
            $redisClient->ping();
        } catch (\Throwable $exception) {
            $components['redis'] = 'error';
            $status = 'error';
            $httpCode = Response::HTTP_SERVICE_UNAVAILABLE;

            $logger->error('HealthCheck: Redis is down', ['exception' => $exception]);
        }

        return $this->json([
            'status' => $status,
            'components' => $components,
            'timestamp' => time(),
        ], $httpCode);
    }
}
