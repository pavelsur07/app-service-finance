<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Facade\BalanceFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/balance')]
final class BalanceController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BalanceFacade $balanceFacade,
    ) {
    }

    #[Route('/', name: 'balance_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $companyId = $this->activeCompanyService->getActiveCompany()->getId();
        $dateParam = $request->query->getString('date');
        $date = '' !== $dateParam ? new \DateTimeImmutable($dateParam) : new \DateTimeImmutable('today');

        $report = $this->balanceFacade->getReportForCompany($companyId, $date);

        return $this->render('balance/index.html.twig', [
            'date' => $report->getDate(),
            'currencies' => $report->getCurrencies(),
            'roots' => $report->getRoots(),
            'totals' => $report->getTotals(),
        ]);
    }
}
