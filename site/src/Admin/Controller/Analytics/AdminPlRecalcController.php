<?php

namespace App\Admin\Controller\Analytics;

use App\Admin\Form\Analytics\PlRecalcFormData;
use App\Admin\Form\Analytics\PlRecalcFormType;
use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Application\DashboardSnapshotService;
use App\Analytics\Application\PeriodResolver;
use App\Company\Entity\Company;
use App\Repository\CompanyRepository;
use App\Repository\PLDailyTotalRepository;
use App\Service\PLRegisterUpdater;
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
        private readonly CompanyRepository $companyRepository,
        private readonly PLRegisterUpdater $plRegisterUpdater,
        private readonly DashboardSnapshotService $dashboardSnapshotService,
        private readonly PeriodResolver $periodResolver,
        private readonly PLDailyTotalRepository $dailyTotalRepository,
    ) {
    }

    #[Route('', name: 'form', methods: ['GET'])]
    public function form(): Response
    {
        $form = $this->createForm(PlRecalcFormType::class, new PlRecalcFormData());

        return $this->render('admin/analytics/pl_recalc.html.twig', [
            'form' => $form->createView(),
            'lastRecalcAt' => $this->dailyTotalRepository->maxUpdatedAtGlobal(),
            'companiesCount' => $this->companyRepository->count([]),
        ]);
    }

    #[Route('', name: 'handle', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $formData = new PlRecalcFormData();
        $form = $this->createForm(PlRecalcFormType::class, $formData);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/analytics/pl_recalc.html.twig', [
                'form' => $form->createView(),
                'lastRecalcAt' => $this->dailyTotalRepository->maxUpdatedAtGlobal(),
                'companiesCount' => $this->companyRepository->count([]),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $query = new SnapshotQuery(
            $formData->preset,
            $formData->from?->format('Y-m-d'),
            $formData->to?->format('Y-m-d'),
        );

        $period = $this->periodResolver->resolve($query);

        /** @var list<Company> $companies */
        $companies = $this->companyRepository->findAll();
        $processed = 0;

        foreach ($companies as $company) {
            if ($formData->recalcPl) {
                $this->plRegisterUpdater->recalcRange($company, $period->getFrom(), $period->getTo());
            }

            if ($formData->warmupSnapshot) {
                $this->dashboardSnapshotService->getSnapshot($company, $period);
            }

            ++$processed;
        }

        $lastUpdatedAt = $this->dailyTotalRepository->maxUpdatedAtGlobal();

        $this->addFlash(
            'success',
            sprintf(
                'Обработано компаний: %d. Период %s — %s. last_updated_at: %s',
                $processed,
                $period->getFrom()->format('Y-m-d'),
                $period->getTo()->format('Y-m-d'),
                $lastUpdatedAt?->format(DATE_ATOM) ?? 'n/a',
            ),
        );

        return $this->redirectToRoute('admin_analytics_pl_recalc_form');
    }
}
