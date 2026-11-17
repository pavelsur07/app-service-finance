<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Category\BindCashflowCategoryGroupCodeAction;
use App\Cash\Exception\CashflowGroupChartBindingException;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowGroupChartBindController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $repo,
        private readonly BindCashflowCategoryGroupCodeAction $bind,
    ) {
    }

    #[Route('/{id}/group-code', name: 'cashflow_group_chart_bind', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $category = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('group-chart'.$company->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела, обновите страницу.');

            return $this->redirectToRoute('cashflow_group_chart');
        }

        $code = (string) $request->request->get('code');
        try {
            ($this->bind)((string) $company->getId(), (string) $category->getId(), $code);
            $this->addFlash('success', sprintf('«%s» привязана к %s.', $category->getName(), $code));
        } catch (CashflowGroupChartBindingException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('cashflow_group_chart');
    }
}
