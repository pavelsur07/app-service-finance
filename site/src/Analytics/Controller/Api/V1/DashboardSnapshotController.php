<?php

namespace App\Analytics\Controller\Api\V1;

use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Domain\Period;
use App\Shared\Service\ActiveCompanyService;
use DateInterval;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class DashboardSnapshotController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DashboardSnapshotService $dashboardSnapshotService,
    ) {
    }

    #[Route('/api/dashboard/v1/snapshot', name: 'analytics_dashboard_snapshot_v1', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $period = $this->parsePeriod($request);

        $snapshot = $this->dashboardSnapshotService->getSnapshot($company, $period);

        return $this->json($snapshot->toArray());
    }

    private function parsePeriod(Request $request): Period
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        if (is_string($from) && is_string($to)) {
            return new Period(new DateTimeImmutable($from), new DateTimeImmutable($to));
        }

        $toDate = new DateTimeImmutable('today');
        $fromDate = $toDate->sub(new DateInterval('P29D'));

        return new Period($fromDate, $toDate);
    }
}
