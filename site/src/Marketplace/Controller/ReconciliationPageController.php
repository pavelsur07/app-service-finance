<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ReconciliationPageController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
    ) {
    }

    #[Route('/marketplace/reconciliation', name: 'marketplace_reconciliation', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->companyService->getActiveCompany();

        return $this->render('marketplace/reconciliation.html.twig', [
            'active_tab' => 'reconciliation',
            'company'    => $company,
        ]);
    }
}
