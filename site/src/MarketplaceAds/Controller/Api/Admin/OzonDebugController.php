<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Message\DownloadOzonAdReportMessage;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ВРЕМЕННЫЙ контроллер для диагностики Ozon Performance API.
 * УДАЛИТЬ после решения инцидента 23.04.2026 с застрявшими
 * pending_reports в state=REQUESTED, хотя у Ozon отчёты уже готовы (OK).
 *
 * Два endpoint'а:
 *   GET /debug/ozon-ads/list-reports?companyId=<UUID>   — посмотреть, что Ozon
 *       возвращает на /statistics/list + прямой опрос каждого UUID.
 *   GET /debug/ozon-ads/force-download?companyId=<UUID> — выгрузить все
 *       in-flight pending_reports company: для каждого спросить
 *       /statistics/{uuid}; если state=OK — обновить БД + dispatch download.
 *
 * БЕЗ авторизации намеренно — временный dev-tool, удаляется сразу после
 * инцидента. URL знают только разработчики.
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
     * Показывает сырой ответ Ozon /statistics/list (до 5 страниц по 100) +
     * проверяет наличие in-flight UUID company в списке + делает прямой
     * опрос /statistics/{uuid} по каждому in-flight UUID.
     */
    #[Route('/list-reports', name: 'list_reports', methods: ['GET'])]
    public function listReports(Request $request): JsonResponse
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
        ]);
    }

    /**
     * Для всех in-flight pending_reports company: напрямую спросить
     * /statistics/{uuid}; если state=OK — обновить БД на OK и диспатчить
     * DownloadOzonAdReportMessage.
     *
     * Временный фикс для «повисших REQUESTED при реально готовом отчёте»
     * из-за того, что /statistics/list возвращает пустой ответ и обычный
     * poller не видит эти UUID.
     */
    #[Route('/force-download', name: 'force_download', methods: ['GET'])]
    public function forceDownload(
        Request $request,
        MessageBusInterface $bus,
        OzonAdPendingReportRepository $repo,
    ): JsonResponse {
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
        $tokenData = json_decode($tokenResp->getContent(false), true);
        $token = $tokenData['access_token'] ?? null;
        if (!$token) {
            return new JsonResponse(['error' => 'token failed', 'body' => $tokenData], 502);
        }

        // 3. Все in-flight pending_reports company
        $pending = $repo->findInFlightByCompany($companyId);
        if ([] === $pending) {
            return new JsonResponse([
                'companyId' => $companyId,
                'message' => 'No in-flight pending reports for this company',
                'pending_count' => 0,
                'results' => [],
            ]);
        }

        // 4. Опрос каждого UUID и обновление БД + dispatch при state=OK
        $results = [];
        foreach ($pending as $p) {
            $uuid = $p->getOzonUuid();
            $resp = $this->httpClient->request(
                'GET',
                "https://api-performance.ozon.ru/api/client/statistics/$uuid",
                [
                    'headers' => ['Authorization' => "Bearer $token", 'Accept' => 'application/json'],
                ]
            );
            $httpCode = $resp->getStatusCode();
            $data = json_decode($resp->getContent(false), true);
            $ozonState = strtoupper((string) ($data['state'] ?? 'UNKNOWN'));

            $action = 'skipped';
            $updatedRows = 0;

            if ($httpCode === 200 && in_array($ozonState, ['OK', 'READY'], true)) {
                $now = new \DateTimeImmutable();
                // markFinalized=NULL — OK ветка finalized_at не выставляет,
                // это сделает DownloadOzonAdReportHandler после успешной загрузки.
                $updatedRows = $repo->updateStateWithSchedule(
                    $companyId,
                    $uuid,
                    OzonAdPendingReportState::OK,
                    $now,
                    null,
                    $p->getPollAttempts(),
                    $now,
                );

                if ($updatedRows > 0) {
                    $bus->dispatch(new DownloadOzonAdReportMessage(
                        companyId: $companyId,
                        pendingReportId: $p->getId(),
                    ));
                    $action = 'marked_ok_and_dispatched_download';
                } else {
                    $action = 'update_returned_0_rows';
                }
            } elseif ($httpCode === 200 && in_array($ozonState, ['ERROR', 'CANCELLED', 'NOT_FOUND'], true)) {
                $action = 'ozon_state_is_terminal_error_not_handled_here';
            } elseif ($httpCode === 200) {
                $action = "ozon_state=$ozonState (not OK, left as-is)";
            } else {
                $action = "ozon_http=$httpCode (left as-is)";
            }

            $results[] = [
                'pendingReportId' => $p->getId(),
                'uuid' => $uuid,
                'db_state_was' => $p->getState()->value,
                'ozon_http' => $httpCode,
                'ozon_state' => $ozonState,
                'updated_rows' => $updatedRows,
                'action' => $action,
            ];
        }

        return new JsonResponse([
            'companyId' => $companyId,
            'pending_count' => count($pending),
            'results' => $results,
        ]);
    }
}
