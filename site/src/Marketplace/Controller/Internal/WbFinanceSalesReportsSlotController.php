<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Internal;

use App\Marketplace\Facade\WbFinanceThrottleFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Слот общей квоты WB finance sales-reports для wb-fetcher
 * (`services/_contracts/wb-finance-quota/v1`): тот же бакет `connection:<id>`,
 * что у импорта Symfony, — сервис и импорт не превышают лимит WB вместе.
 */
final class WbFinanceSalesReportsSlotController extends AbstractController
{
    public function __construct(
        private readonly WbFinanceThrottleFacade $throttle,
    ) {
    }

    #[Route('/internal/wb-finance/sales-reports/slot', name: 'internal_wb_finance_sales_reports_slot', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $ref = \is_array($body) ? ($body['connection_ref'] ?? null) : null;
        if (!\is_string($ref) || !WbFinanceQuotaRequest::isConnectionRef($ref)) {
            return WbFinanceQuotaRequest::invalid('connection_ref — UUID подключения WB');
        }

        $denied = $this->throttle->reserveSalesReportsSlot(WbFinanceQuotaRequest::bucket($ref));
        $payload = null === $denied
            ? ['granted' => true]
            : ['granted' => false, 'wait_seconds' => $denied->waitSeconds, 'shared_cooldown' => $denied->sharedCooldown];

        return $this->json($payload, 200, ['Cache-Control' => 'no-store']);
    }
}
