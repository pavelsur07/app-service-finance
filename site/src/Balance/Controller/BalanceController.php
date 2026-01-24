<?php

namespace App\Balance\Controller;

use App\Balance\Service\BalanceBuilder;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/balance')]
class BalanceController extends AbstractController
{
    #[Route('/', name: 'balance_index', methods: ['GET'])]
    public function index(Request $request, ActiveCompanyService $companyService, BalanceBuilder $builder): Response
    {
        $company = $companyService->getActiveCompany();
        $dateParam = $request->query->get('date');
        $date = $dateParam ? new \DateTimeImmutable($dateParam) : new \DateTimeImmutable('today');

        $result = $builder->buildForCompanyAndDate($company, $date);

        return $this->render('balance/index.html.twig', [
            'date' => $date,
            'currencies' => $result['currencies'],
            'roots' => $result['roots'],
            'totals' => $result['totals'],
        ]);
    }
}
