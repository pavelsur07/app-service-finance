<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $repo,
        private readonly CashflowCategoryUsageQuery $usageQuery,
    ) {
    }

    #[Route('/', name: 'cashflow_category_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->companyService->getActiveCompany();
        $items = $this->repo->findRootByCompany($company);

        return $this->render('cashflow_category/index.html.twig', ['items' => $items, 'usage' => ($this->usageQuery)((string) $company->getId())]);
    }
}
