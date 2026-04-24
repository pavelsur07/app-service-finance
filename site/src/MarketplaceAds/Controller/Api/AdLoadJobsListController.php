<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/load-jobs', name: 'marketplace_ads_load_jobs_list', methods: ['GET'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class AdLoadJobsListController extends AbstractController
{
    private const LIMIT = 10;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdLoadJobRepository $adLoadJobRepository,
        private readonly AdScheduledBatchRepository $adScheduledBatchRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $jobs = $this->adLoadJobRepository->findRecentByCompanyAndMarketplace(
            $companyId,
            MarketplaceType::OZON,
            self::LIMIT,
        );

        $canExtract = $this->isGranted('ROLE_COMPANY_OWNER');

        $items = [];
        foreach ($jobs as $job) {
            // Batch-агрегат для нового cron-driven pipeline (Task-11.3+).
            // Для jobs старого Messenger-pipeline'а countStatesForJob вернёт [] —
            // UI отрисует старый «Чанки: N» путь через hasBatches=false.
            // Ключи — AdScheduledBatchState::value (см. countStatesForJob SQL).
            $stats = $this->adScheduledBatchRepository->countStatesForJob($job->getId(), $companyId);
            $ok = $stats[AdScheduledBatchState::OK->value] ?? 0;
            $failedLike = ($stats[AdScheduledBatchState::FAILED->value] ?? 0)
                + ($stats[AdScheduledBatchState::ABANDONED->value] ?? 0);
            $pending = ($stats[AdScheduledBatchState::PLANNED->value] ?? 0)
                + ($stats[AdScheduledBatchState::IN_FLIGHT->value] ?? 0);
            $totalBatches = $ok + $failedLike + $pending;
            $hasBatches = $totalBatches > 0;

            // Task-12-test: CSRF-токен для кнопки «Обработать». Показываем
            // токен только если (1) user имеет ROLE_COMPANY_OWNER (требование
            // контроллера-экстракта) и (2) job в терминальном состоянии с
            // batch'ами нового pipeline'а. Без токена кнопка в UI не рисуется.
            $statusValue = $job->getStatus()->value;
            $extractToken = ($canExtract && $hasBatches && in_array($statusValue, ['completed', 'partial_success'], true))
                ? $this->csrfTokenManager->getToken('extract-batches-'.$job->getId())->getValue()
                : null;

            $items[] = [
                'id' => $job->getId(),
                'status' => $statusValue,
                'dateFrom' => $job->getDateFrom()->format('Y-m-d'),
                'dateTo' => $job->getDateTo()->format('Y-m-d'),
                'chunksTotal' => $job->getChunksTotal(),
                'createdAt' => $job->getCreatedAt()->format('d.m.Y H:i'),
                'finishedAt' => $job->getFinishedAt()?->format('d.m.Y H:i'),
                'lastError' => $job->getFailureReason(),
                'batchStats' => [
                    'total' => $totalBatches,
                    'ok' => $ok,
                    'failed' => $failedLike,
                    'pending' => $pending,
                    'hasBatches' => $hasBatches,
                ],
                'extractToken' => $extractToken,
            ];
        }

        return $this->json(['items' => $items]);
    }
}
