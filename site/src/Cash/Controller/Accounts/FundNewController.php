<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Application\SaveMoneyFundAction;
use App\Cash\Form\Accounts\MoneyFundType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\FeatureFlagService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/funds')]
final class FundNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SaveMoneyFundAction $saveFund,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    #[Route('/new', name: 'finance_funds_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $this->assertFeatureEnabled();
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $fund = $this->saveFund->createDraft($company);
        $form = $this->createForm(MoneyFundType::class, $fund);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->saveFund)($fund);
            $this->addFlash('success', 'Фонд создан.');

            return $this->redirectToRoute('finance_funds_index');
        }

        return $this->render('fund/new.html.twig', ['form' => $form->createView()]);
    }

    private function assertFeatureEnabled(): void
    {
        if (!$this->featureFlagService->isFundsAndWidgetEnabled()) {
            throw $this->createNotFoundException();
        }
    }
}
