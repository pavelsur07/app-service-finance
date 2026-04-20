<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api\Admin;

use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonDebugFetcher;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin debug endpoints для ручной отладки Ozon Performance API.
 *
 * Все методы:
 *  - требуют ROLE_SUPER_ADMIN,
 *  - принимают companyId (query / body),
 *  - возвращают JsonResponse с сырыми ответами Ozon (без скрытия ошибок),
 *  - логируют факт вызова в канал marketplace_ads.
 *
 * Это debug-инструмент: он намеренно обходит async-пайплайн, не создаёт
 * AdLoadJob/AdRawDocument и ничего не пишет в БД.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OzonDebugController extends AbstractController
{
    public function __construct(
        private readonly OzonDebugFetcher $debugFetcher,
        #[Autowire(service: 'monolog.logger.marketplace_ads')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/api/marketplace-ads/admin/ozon/debug/token',
        name: 'marketplace_ads_admin_ozon_debug_token',
        methods: ['GET'],
    )]
    public function token(Request $request): JsonResponse
    {
        $companyId = $this->requireCompanyId($request->query->get('companyId'));
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $this->logger->info('Ozon debug call: token', ['companyId' => $companyId]);

        try {
            $result = $this->debugFetcher->fetchAccessToken($companyId);
        } catch (OzonPermanentApiException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502, $e);
        }

        $token = $result['access_token'];
        if ('' === $token) {
            $tokenPrefix = '';
        } elseif (mb_strlen($token) > 20) {
            $tokenPrefix = mb_substr($token, 0, 20).'...';
        } else {
            $tokenPrefix = $token;
        }

        $rawBody = $result['ozon_raw_body'];
        if (array_key_exists('access_token', $rawBody)) {
            $rawBody['access_token'] = '***REDACTED***';
        }

        return $this->json([
            'companyId' => $companyId,
            'access_token_prefix' => $tokenPrefix,
            'expires_in' => $result['expires_in'],
            'issued_at' => $result['issued_at'],
            'ozon_raw_response_status' => $result['ozon_raw_response_status'],
            'ozon_raw_body' => $rawBody,
        ]);
    }

    #[Route(
        '/api/marketplace-ads/admin/ozon/debug/campaigns',
        name: 'marketplace_ads_admin_ozon_debug_campaigns',
        methods: ['GET'],
    )]
    public function campaigns(Request $request): JsonResponse
    {
        $companyId = $this->requireCompanyId($request->query->get('companyId'));
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $this->logger->info('Ozon debug call: campaigns', ['companyId' => $companyId]);

        try {
            $result = $this->debugFetcher->listCampaigns($companyId);
        } catch (OzonPermanentApiException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502, $e);
        }

        return $this->json([
            'companyId' => $companyId,
            'status_code' => $result['status_code'],
            'total' => $result['total'],
            'states_breakdown' => $result['states_breakdown'],
            'list' => $result['list'],
            'raw_body' => $result['raw_body'],
        ]);
    }

    #[Route(
        '/api/marketplace-ads/admin/ozon/debug/statistics/request',
        name: 'marketplace_ads_admin_ozon_debug_statistics_request',
        methods: ['POST'],
    )]
    public function statisticsRequest(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('Body: ожидается JSON-объект', 400);
        }

        $companyId = $this->requireCompanyId($payload['companyId'] ?? null);
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $campaigns = $payload['campaigns'] ?? null;
        if (!is_array($campaigns) || [] === $campaigns) {
            return $this->error('campaigns: массив с ID обязателен', 400);
        }
        $campaignIds = [];
        foreach ($campaigns as $c) {
            if (is_scalar($c)) {
                $id = trim((string) $c);
                if ('' !== $id) {
                    $campaignIds[] = $id;
                }
            }
        }
        if ([] === $campaignIds) {
            return $this->error('campaigns: не нашлось валидных ID', 400);
        }

        $fromStr = isset($payload['from']) ? (string) $payload['from'] : '';
        $toStr = isset($payload['to']) ? (string) $payload['to'] : '';
        $utc = new \DateTimeZone('UTC');
        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromStr, $utc);
        $dateTo = \DateTimeImmutable::createFromFormat('!Y-m-d', $toStr, $utc);
        if (false === $dateFrom || $dateFrom->format('Y-m-d') !== $fromStr) {
            return $this->error('from: ожидается YYYY-MM-DD', 400);
        }
        if (false === $dateTo || $dateTo->format('Y-m-d') !== $toStr) {
            return $this->error('to: ожидается YYYY-MM-DD', 400);
        }

        $groupBy = isset($payload['groupBy']) ? (string) $payload['groupBy'] : 'NO_GROUP_BY';

        $this->logger->info('Ozon debug call: statistics/request', [
            'companyId' => $companyId,
            'campaigns' => $campaignIds,
            'from' => $fromStr,
            'to' => $toStr,
            'groupBy' => $groupBy,
        ]);

        try {
            $result = $this->debugFetcher->requestStatistics($companyId, $campaignIds, $dateFrom, $dateTo, $groupBy);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (OzonPermanentApiException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502, $e);
        }

        return $this->json([
            'companyId' => $companyId,
            'request_body' => $result['request_body'],
            'uuid' => $result['uuid'],
            'ozon_status_code' => $result['ozon_status_code'],
            'ozon_raw_response' => $result['ozon_raw_response'],
        ]);
    }

    #[Route(
        '/api/marketplace-ads/admin/ozon/debug/statistics/status',
        name: 'marketplace_ads_admin_ozon_debug_statistics_status',
        methods: ['GET'],
    )]
    public function statisticsStatus(Request $request): JsonResponse
    {
        $companyId = $this->requireCompanyId($request->query->get('companyId'));
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $uuid = trim((string) $request->query->get('uuid', ''));
        if ('' === $uuid) {
            return $this->error('uuid: обязательный параметр', 400);
        }

        $this->logger->info('Ozon debug call: statistics/status', [
            'companyId' => $companyId,
            'uuid' => $uuid,
        ]);

        try {
            $result = $this->debugFetcher->checkStatus($companyId, $uuid);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (OzonPermanentApiException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502, $e);
        }

        return $this->json([
            'companyId' => $companyId,
            'uuid' => $result['uuid'],
            'state' => $result['state'],
            'status_code' => $result['status_code'],
            'ozon_raw_response' => $result['ozon_raw_response'],
        ]);
    }

    #[Route(
        '/api/marketplace-ads/admin/ozon/debug/statistics/download',
        name: 'marketplace_ads_admin_ozon_debug_statistics_download',
        methods: ['GET'],
    )]
    public function statisticsDownload(Request $request): Response
    {
        $companyId = $this->requireCompanyId($request->query->get('companyId'));
        if ($companyId instanceof JsonResponse) {
            return $companyId;
        }

        $uuid = trim((string) $request->query->get('uuid', ''));
        if ('' === $uuid) {
            return $this->error('uuid: обязательный параметр', 400);
        }

        $raw = '1' === (string) $request->query->get('raw', '0');

        $this->logger->info('Ozon debug call: statistics/download', [
            'companyId' => $companyId,
            'uuid' => $uuid,
            'raw' => $raw,
        ]);

        try {
            $result = $this->debugFetcher->downloadReport($companyId, $uuid);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (OzonPermanentApiException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502, $e);
        }

        if ($raw) {
            $filename = sprintf('ozon-report-%s.%s', $uuid, $result['was_zip'] ? 'zip' : 'csv');
            $response = new Response($result['raw_bytes']);
            $response->headers->set(
                'Content-Type',
                $result['was_zip'] ? 'application/zip' : 'text/csv; charset=utf-8',
            );
            $response->headers->set(
                'Content-Disposition',
                $response->headers->makeDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    $filename,
                ),
            );

            return $response;
        }

        return $this->json([
            'companyId' => $companyId,
            'uuid' => $uuid,
            'was_zip' => $result['was_zip'],
            'size_bytes' => $result['size_bytes'],
            'content_preview' => $result['content_preview'],
            'files_in_zip' => $result['files_in_zip'],
        ]);
    }

    private function requireCompanyId(mixed $raw): JsonResponse|string
    {
        if (!is_string($raw)) {
            return $this->error('companyId: обязательный параметр', 400);
        }
        $value = trim($raw);
        if ('' === $value) {
            return $this->error('companyId: обязательный параметр', 400);
        }
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return $this->error('companyId: ожидается UUID', 400);
        }

        return $value;
    }

    private function error(string $message, int $status, ?\Throwable $e = null): JsonResponse
    {
        $payload = ['error' => $message];
        if (null !== $e) {
            $payload['exception_class'] = $e::class;
        }

        return $this->json($payload, $status);
    }
}
