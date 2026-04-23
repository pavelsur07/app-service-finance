<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller;

use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\DBAL\Connection;

/**
 * ВРЕМЕННЫЙ контроллер для диагностики Ozon Performance API.
 * УДАЛИТЬ после решения инцидента с застрявшими pending_reports в state=REQUESTED.
 */
#[Route('/debug/ozon-ads', name: 'debug_ozon_ads_')]
final class OzonDebugController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Показывает сырой ответ Ozon /statistics/list + проверяет наличие
     * наших застрявших UUID.
     *
     * Пример: GET /debug/ozon-ads/list-reports?companyId=b57d7682-505f-4b74-86f8-953d32d59874
     */
    #[Route('/list-reports', name: 'list_reports', methods: ['GET'])]
    public function listReports(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $companyId = $request->query->get('companyId');
        if (!$companyId) {
            return new JsonResponse(['error' => 'companyId query param required'], 400);
        }

        // 1. Credentials
        $row = $this->connection->fetchAssociative(
            'SELECT client_id, api_key FROM marketplace_connections
             WHERE company_id = :c AND marketplace = :m AND connection_type = :t',
            ['c' => $companyId, 'm' => 'ozon', 't' => 'performance']
        );
        if (!$row) {
            return new JsonResponse(['error' => "No creds for companyId=$companyId"], 404);
        }

        // 2. Token
        $tokenResp = $this->httpClient->request('POST', 'https://api-performance.ozon.ru/api/client/token', [
            'json' => [
                'client_id' => $row['client_id'],
                'client_secret' => $row['api_key'],
                'grant_type' => 'client_credentials',
            ],
        ]);
        $tokenCode = $tokenResp->getStatusCode();
        $tokenData = json_decode($tokenResp->getContent(false), true);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) {
            return new JsonResponse([
                'step' => 'token',
                'http_code' => $tokenCode,
                'body' => $tokenData,
            ], 502);
        }

        // 3. Наши застрявшие UUID
        $ourUuids = $this->connection->fetchFirstColumn(
            'SELECT ozon_uuid FROM marketplace_ad_pending_reports
             WHERE company_id = :c AND finalized_at IS NULL',
            ['c' => $companyId]
        );

        // 4. Собрать все страницы Ozon /statistics/list
        $allItems = [];
        $pages = [];
        $pageSize = 100;

        for ($page = 1; $page <= 5; $page++) {
            $listResp = $this->httpClient->request(
                'GET',
                "https://api-performance.ozon.ru/api/client/statistics/list?page=$page&pageSize=$pageSize",
                [
                    'headers' => ['Authorization' => "Bearer $token", 'Accept' => 'application/json'],
                ]
            );
            $listCode = $listResp->getStatusCode();
            $listBody = $listResp->getContent(false);
            $data = json_decode($listBody, true);
            $items = $data['items'] ?? $data['list'] ?? [];
            $pages[] = [
                'page' => $page,
                'http_code' => $listCode,
                'top_level_keys' => is_array($data) ? array_keys($data) : null,
                'items_count' => count($items),
                'total' => $data['total'] ?? null,
            ];
            foreach ($items as $item) {
                $allItems[] = $item;
            }
            if (count($items) < $pageSize) {
                break;
            }
        }

        // 5. Проверка наших UUID в ответе
        $ourUuidsStatus = [];
        foreach ($ourUuids as $u) {
            $found = false;
            $foundData = null;
            foreach ($allItems as $item) {
                foreach ($item as $val) {
                    if (is_string($val) && strcasecmp($val, $u) === 0) {
                        $found = true;
                        $foundData = $item;
                        break 2;
                    }
                }
            }
            $ourUuidsStatus[] = [
                'uuid' => $u,
                'found_in_list' => $found,
                'data' => $foundData,
            ];
        }

        // 6. Прямой опрос /statistics/{uuid} по каждому нашему UUID
        $directProbes = [];
        foreach ($ourUuids as $uuid) {
            $resp = $this->httpClient->request(
                'GET',
                "https://api-performance.ozon.ru/api/client/statistics/$uuid",
                [
                    'headers' => ['Authorization' => "Bearer $token", 'Accept' => 'application/json'],
                ]
            );
            $directProbes[] = [
                'uuid' => $uuid,
                'http_code' => $resp->getStatusCode(),
                'body' => json_decode($resp->getContent(false), true),
            ];
        }

        return new JsonResponse([
            'companyId' => $companyId,
            'client_id' => substr($row['client_id'], 0, 16) . '...',
            'token_ok' => true,
            'token_http_code' => $tokenCode,
            'list_pages' => $pages,
            'total_items_collected' => count($allItems),
            'first_5_items' => array_slice($allItems, 0, 5),
            'last_5_items' => array_slice($allItems, -5),
            'our_uuids' => $ourUuidsStatus,
            'direct_probes_by_uuid' => $directProbes,
        ], 200, [], false);
    }
}
