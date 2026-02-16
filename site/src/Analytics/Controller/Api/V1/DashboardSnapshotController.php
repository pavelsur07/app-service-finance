<?php

namespace App\Analytics\Controller\Api\V1;

use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\PeriodResolver;
use App\Shared\Service\ActiveCompanyService;
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
        private readonly PeriodResolver $periodResolver,
    ) {
    }

    #[Route('/api/dashboard/v1/snapshot', name: 'analytics_dashboard_snapshot_v1', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $period = $this->periodResolver->resolve(new SnapshotQuery(
                preset: $this->stringOrNull($request->query->get('preset')),
                from: $this->stringOrNull($request->query->get('from')),
                to: $this->stringOrNull($request->query->get('to')),
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->json([
                'type' => 'validation_error',
                'message' => $exception->getMessage(),
                'details' => [
                    'preset' => $request->query->get('preset'),
                    'from' => $request->query->get('from'),
                    'to' => $request->query->get('to'),
                ],
            ], 422);
        }

        $company = $this->activeCompanyService->getActiveCompany();
        $snapshot = $this->dashboardSnapshotService->getSnapshot($company, $period);

        return $this->json($snapshot->toArray());
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
