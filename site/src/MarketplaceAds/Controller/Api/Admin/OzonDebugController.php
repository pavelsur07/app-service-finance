<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ВРЕМЕННЫЙ контроллер для диагностики Ozon Performance API.
 * УДАЛИТЬ после решения инцидента 23.04.2026.
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
     * Возвращает ВСЕ кампании company из Ozon /api/client/campaign,
     * чтобы увидеть какие campaignId у неё существуют и когда они были созданы.
     *
     * Пример: GET /debug/ozon-ads/all-campaigns?companyId=b57d7682-505f-4b74-86f8-953d32d59874
     */
    #[Route('/all-campaigns', name: 'all_campaigns', methods: ['GET'])]
    public function allCampaigns(Request $request): JsonResponse
    {
        $companyId = $request->query->get('companyId');
        if (!$companyId) {
            return new JsonResponse(['error' => 'companyId query param required'], 400);
        }

        // Credentials
        $row = $this->connection->fetchAssociative(
            'SELECT client_id, api_key FROM marketplace_connections
             WHERE company_id = :c AND marketplace = :m AND connection_type = :t',
            ['c' => $companyId, 'm' => 'ozon', 't' => 'performance']
        );
        if (!$row) {
            return new JsonResponse(['error' => "No creds for companyId=$companyId"], 404);
        }

        // Token
        $tokenResp = $this->httpClient->request('POST', 'https://api-performance.ozon.ru/api/client/token', [
            'json' => [
                'client_id' => $row['client_id'],
                'client_secret' => $row['api_key'],
                'grant_type' => 'client_credentials',
            ],
        ]);
        $tokenData = json_decode($tokenResp->getContent(false), true);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) {
            return new JsonResponse(['error' => 'token failed', 'body' => $tokenData], 502);
        }

        // Ozon /api/client/campaign — список всех кампаний
        $campaignResp = $this->httpClient->request(
            'GET',
            'https://api-performance.ozon.ru/api/client/campaign',
            [
                'headers' => ['Authorization' => "Bearer $token", 'Accept' => 'application/json'],
            ]
        );
        $campaignCode = $campaignResp->getStatusCode();
        $campaignBody = $campaignResp->getContent(false);
        $campaignData = json_decode($campaignBody, true);

        if (!is_array($campaignData)) {
            return new JsonResponse([
                'step' => 'campaign-list',
                'http_code' => $campaignCode,
                'raw_body' => substr($campaignBody, 0, 500),
            ], 502);
        }

        $campaigns = $campaignData['list'] ?? $campaignData['campaigns'] ?? $campaignData['items'] ?? [];

        // Парсим и готовим сводку
        $enriched = [];
        foreach ($campaigns as $c) {
            $enriched[] = [
                'campaignId' => $c['id'] ?? $c['campaignId'] ?? null,
                'title' => $c['title'] ?? $c['campaignName'] ?? null,
                'state' => $c['state'] ?? null,
                'advObjectType' => $c['advObjectType'] ?? null,
                'createdAt' => $c['createdAt'] ?? null,
                'updatedAt' => $c['updatedAt'] ?? null,
                'fromDate' => $c['fromDate'] ?? null,
                'toDate' => $c['toDate'] ?? null,
                'budget' => $c['budget'] ?? null,
                'raw' => $c,
            ];
        }

        // Статистика
        $statesByState = [];
        $byYearMonth = [];
        foreach ($enriched as $e) {
            $st = $e['state'] ?? 'UNKNOWN';
            $statesByState[$st] = ($statesByState[$st] ?? 0) + 1;

            if ($e['createdAt']) {
                $ym = substr($e['createdAt'], 0, 7);
                $byYearMonth[$ym] = ($byYearMonth[$ym] ?? 0) + 1;
            }
        }

        ksort($byYearMonth);

        return new JsonResponse([
            'companyId' => $companyId,
            'client_id' => substr($row['client_id'], 0, 16) . '...',
            'http_code' => $campaignCode,
            'total_campaigns' => count($enriched),
            'top_level_keys' => array_keys($campaignData),
            'by_state' => $statesByState,
            'by_year_month_created' => $byYearMonth,
            'campaigns' => $enriched,
        ]);
    }
}
