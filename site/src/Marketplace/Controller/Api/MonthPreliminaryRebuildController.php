<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\RebuildPreliminaryForPeriodMessage;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ручной запуск пересборки предварительного ОПиУ за период.
 * Rate-limit: 1/мин на пару (companyId, marketplace, year, month).
 */
#[Route('/marketplace/month-close/preliminary')]
#[IsGranted('ROLE_USER')]
final class MonthPreliminaryRebuildController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService     $activeCompanyService,
        private readonly MessageBusInterface      $messageBus,
        private readonly RateLimiterFactory       $marketplacePreliminaryRebuildLimiter,
        private readonly LoggerInterface          $logger,
    ) {
    }

    #[Route('/rebuild', name: 'marketplace_month_preliminary_rebuild', methods: ['POST'])]
    public function rebuild(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $user      = $this->getUser();

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $marketplace = (string) ($payload['marketplace'] ?? '');
        $year        = (int)    ($payload['year']  ?? 0);
        $month       = (int)    ($payload['month'] ?? 0);

        if (MarketplaceType::tryFrom($marketplace) === null) {
            return new JsonResponse(
                ['error' => 'Некорректный маркетплейс.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($year < 2000 || $month < 1 || $month > 12) {
            return new JsonResponse(
                ['error' => 'Некорректный период.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $key = sprintf('preliminary-rebuild-%s-%s-%d-%02d', $companyId, $marketplace, $year, $month);
        $limiter = $this->marketplacePreliminaryRebuildLimiter->create($key);

        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse(
                ['error' => 'Подождите минуту перед следующим пересчётом.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $this->messageBus->dispatch(new RebuildPreliminaryForPeriodMessage(
            companyId:   $companyId,
            marketplace: $marketplace,
            year:        $year,
            month:       $month,
            actorUserId: (string) $user->getId(),
        ));

        $this->logger->info('[PreliminaryRebuild] Manual rebuild requested', [
            'company_id'  => $companyId,
            'marketplace' => $marketplace,
            'year'        => $year,
            'month'       => $month,
            'user_id'     => (string) $user->getId(),
        ]);

        return new JsonResponse(
            ['queued' => true],
            Response::HTTP_ACCEPTED,
        );
    }
}
