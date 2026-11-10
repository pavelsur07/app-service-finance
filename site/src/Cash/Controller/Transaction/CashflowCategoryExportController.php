<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cashflow-categories')]
final class CashflowCategoryExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowCategoryRepository $repo,
        private readonly \App\Cash\Http\CashflowCategoryPage $page,
    ) {
    }

    #[Route('/export/json', name: 'cashflow_category_export_json', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->companyService->getActiveCompany();
        $items = $this->repo->findRootByCompany($company);
        $payload = array_map(fn (CashflowCategory $category): array => $this->page->serializeCategory($category), $items);
        $response = new Response(json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('cashflow-categories-%s.json', (new \DateTimeImmutable())->format('Y-m-d'))));

        return $response;
    }
}
