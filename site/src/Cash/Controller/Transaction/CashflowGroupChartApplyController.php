<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\ApplyCashflowGroupChartAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowGroupChartApplyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly ApplyCashflowGroupChartAction $apply,
    ) {
    }

    #[Route('/group-chart/apply', name: 'cashflow_group_chart_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        if (!$this->isCsrfTokenValid('group-chart'.$company->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, обновите страницу.');

            return $this->redirectToRoute('cashflow_group_chart');
        }

        $result = ($this->apply)((string) $company->getId());
        $this->addFlash('success', sprintf('Привязано: %d, создано: %d, конфликтов: %d.', \count($result->bind), \count($result->create), \count($result->conflicts)));

        return $this->redirectToRoute('cashflow_group_chart');
    }
}
