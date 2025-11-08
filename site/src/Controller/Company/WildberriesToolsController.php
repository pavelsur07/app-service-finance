<?php

namespace App\Controller\Company;

use App\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[IsGranted('ROLE_USER')]
#[Route('/company/wb/tools', name: 'company_wb_tools_')]
final class WildberriesToolsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CsrfTokenManagerInterface $csrf
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->render('company/wb/tools.html.twig', [
            'company' => $company,
            'csrf_finance' => $this->csrf->getToken('wb_finance_run')->getValue(),
            'csrf_sales'   => $this->csrf->getToken('wb_sales_run')->getValue(),
        ]);
    }

    #[Route('/run/finance', name: 'run_finance', methods: ['POST'])]
    public function runFinance(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $token = new CsrfToken('wb_finance_run', (string) $request->request->get('_token'));
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('danger', 'Неверный CSRF токен для запуска финансовых отчётов WB.');
            return $this->redirectToRoute('company_wb_tools_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();

        // TODO: подключить реальный сервис загрузки фин. отчётов WB для $company
        // Пример: $this->wbFinanceFetcher->runForCompany($company->getId());

        $this->addFlash('success', sprintf(
            'Запущена задача: Финансовые отчёты WB для компании «%s».',
            (string) $company->getName()
        ));

        return $this->redirectToRoute('company_wb_tools_index');
    }

    #[Route('/run/sales', name: 'run_sales', methods: ['POST'])]
    public function runSales(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $token = new CsrfToken('wb_sales_run', (string) $request->request->get('_token'));
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('danger', 'Неверный CSRF токен для запуска продаж WB.');
            return $this->redirectToRoute('company_wb_tools_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();

        // TODO: подключить реальный сервис загрузки продаж WB для $company
        // Пример: $this->wbSalesFetcher->runForCompany($company->getId());

        $this->addFlash('success', sprintf(
            'Запущена задача: Продажи WB для компании «%s».',
            (string) $company->getName()
        ));

        return $this->redirectToRoute('company_wb_tools_index');
    }
}
