<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\SyncCashflowGroupChartAction;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Экран «Эталон группы»: план синхронизации активной компании и статьи без кода. Только чтение. */
#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowGroupChartController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly SyncCashflowGroupChartAction $sync,
        private readonly CompanyFacade $companies,
    ) {
    }

    #[Route('/group-chart', name: 'cashflow_group_chart', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->companyService->getActiveCompany();
        $plan = $this->sync->plan((string) $company->getId());
        $head = null !== $plan->headCompanyId ? $this->companies->findById($plan->headCompanyId) : null;

        return $this->render('cashflow_category/group_chart.html.twig', [
            'plan' => $plan,
            'headName' => $head?->getName(),
            'companyId' => (string) $company->getId(),
        ]);
    }
}
