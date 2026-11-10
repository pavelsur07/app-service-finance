<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Repository\Accounts\MoneyFundRepository;
use App\Cash\Service\Accounts\FundBalanceService;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/funds')]
final class FundIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyFundRepository $fundRepository,
        private readonly FundBalanceService $fundBalanceService,
    ) {
    }

    #[Route('', name: 'finance_funds_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        /* $this->assertFeatureEnabled(); */
        $company = $this->activeCompanyService->getActiveCompany();
        $funds = $this->fundRepository->findByCompany($company);
        $balances = [];
        $balancesDecimal = [];
        foreach ($this->fundBalanceService->getFundBalances((string) $company->getId()) as $row) {
            $balances[$row['fundId']] = $row['balanceMinor'];
            $balancesDecimal[$row['fundId']] = Money::fromMinor($row['balanceMinor'], $row['currency'])->toDecimalString();
        }

        return $this->render('fund/index.html.twig', ['funds' => $funds, 'balances' => $balances, 'balancesDecimal' => $balancesDecimal]);
    }
}
