<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Application\Accounts\SaveMoneyAccountAction;
use App\Cash\Form\Accounts\MoneyAccountType as MoneyAccountFormType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/accounts')]
final class MoneyAccountNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly SaveMoneyAccountAction $saveMoneyAccount,
    ) {
    }

    #[Route('/new', name: 'money_account_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $account = $this->saveMoneyAccount->createDraft($company);
        $form = $this->createForm(MoneyAccountFormType::class, $account, ['expanded_type' => true, 'currency_choices' => true]);
        foreach (['minimumSafeBalance', 'bankName', 'iban', 'bic', 'corrAccount', 'location', 'responsiblePerson', 'provider', 'walletId'] as $field) {
            $form->remove($field);
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->saveMoneyAccount)($account);

            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/new.html.twig', ['form' => $form->createView()]);
    }
}
