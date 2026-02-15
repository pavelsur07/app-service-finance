<?php

namespace App\Admin\Controller\Analytics;

use App\Admin\Form\Analytics\PlRecalcFormData;
use App\Admin\Form\Analytics\PlRecalcFormType;
use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\PeriodResolver;
use App\Repository\PLDailyTotalRepository;
use App\Service\PLRegisterUpdater;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/analytics/pl-recalc', name: 'admin_analytics_pl_recalc_')]
final class AdminPlRecalcController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly PLRegisterUpdater $plRegisterUpdater,
        private readonly DashboardSnapshotService $dashboardSnapshotService,
        private readonly PeriodResolver $periodResolver,
        private readonly PLDailyTotalRepository $dailyTotalRepository,
    ) {
    }

    #[Route('', name: 'form', methods: ['GET'])]
    public function form(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $form = $this->createForm(PlRecalcFormType::class, new PlRecalcFormData());

        return $this->render('admin/analytics/pl_recalc.html.twig', [
            'form' => $form->createView(),
            'lastRecalcAt' => $this->dailyTotalRepository->maxUpdatedAtForCompany($company),
        ]);
    }

    #[Route('', name: 'handle', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $formData = new PlRecalcFormData();
        $form = $this->createForm(PlRecalcFormType::class, $formData);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/analytics/pl_recalc.html.twig', [
                'form' => $form->createView(),
                'lastRecalcAt' => $this->dailyTotalRepository->maxUpdatedAtForCompany($company),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $query = new SnapshotQuery(
            $formData->preset,
            $formData->from?->format('Y-m-d'),
            $formData->to?->format('Y-m-d'),
        );

        $period = $this->periodResolver->resolve($query);

        if ($formData->recalcPl) {
            $this->plRegisterUpdater->recalcRange($company, $period->getFrom(), $period->getTo());
        }

        $lastUpdatedAt = $this->dailyTotalRepository->maxUpdatedAtForCompany($company);
        if ($formData->warmupSnapshot) {
            $snapshot = $this->dashboardSnapshotService->getSnapshot($company, $period);
            $payload = $snapshot->toArray();
            $lastUpdatedAtRaw = $payload['context']['last_updated_at'] ?? null;

            if (is_string($lastUpdatedAtRaw) && '' !== $lastUpdatedAtRaw) {
                $lastUpdatedAt = new \DateTimeImmutable($lastUpdatedAtRaw);
            }
        }

        $this->addFlash(
            'success',
            sprintf(
                'PL регистр обработан за период %s — %s. last_updated_at: %s',
                $period->getFrom()->format('Y-m-d'),
                $period->getTo()->format('Y-m-d'),
                $lastUpdatedAt?->format(DATE_ATOM) ?? 'n/a',
            ),
        );

        return $this->redirectToRoute('admin_analytics_pl_recalc_form');
    }
}
