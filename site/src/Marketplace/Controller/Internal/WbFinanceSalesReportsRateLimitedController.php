<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Internal;

use App\Marketplace\Facade\WbFinanceThrottleFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 429 от WB, полученный wb-fetcher: общий cooldown кабинета
 * (`services/_contracts/wb-finance-quota/v1`) — импорт Symfony тоже ждёт.
 */
final class WbFinanceSalesReportsRateLimitedController extends AbstractController
{
    private const DEFAULT_RETRY_AFTER_SECONDS = 70;
    private const MAX_RETRY_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly WbFinanceThrottleFacade $throttle,
    ) {
    }

    #[Route('/internal/wb-finance/sales-reports/rate-limited', name: 'internal_wb_finance_sales_reports_rate_limited', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $ref = \is_array($body) ? ($body['connection_ref'] ?? null) : null;
        if (!\is_string($ref) || !WbFinanceQuotaRequest::isConnectionRef($ref)) {
            return WbFinanceQuotaRequest::invalid('connection_ref — UUID подключения WB');
        }
        $retryAfter = $body['retry_after_seconds'] ?? null;
        if (null !== $retryAfter && (!\is_int($retryAfter) || $retryAfter < 1 || $retryAfter > self::MAX_RETRY_AFTER_SECONDS)) {
            return WbFinanceQuotaRequest::invalid('retry_after_seconds — целое 1…86400 или null');
        }

        $seconds = $this->throttle->registerSalesReportsRemote429(WbFinanceQuotaRequest::bucket($ref), $retryAfter, self::DEFAULT_RETRY_AFTER_SECONDS);

        return $this->json(['cooldown_seconds' => $seconds], 200, ['Cache-Control' => 'no-store']);
    }
}
