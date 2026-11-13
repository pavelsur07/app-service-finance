<?php

declare(strict_types=1);

namespace App\Cash\Controller\Accounts;

use App\Cash\Application\Accounts\SaveMoneyAccountAction;
use App\Cash\Form\Accounts\MoneyAccountType as MoneyAccountFormType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/accounts')]
final class MoneyAccountEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MoneyAccountRepository $accountRepository,
        private readonly SaveMoneyAccountAction $saveMoneyAccount,
    ) {
    }

    #[Route('/{id}/edit', name: 'money_account_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $account = $this->accountRepository->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        $form = $this->createForm(MoneyAccountFormType::class, $account, ['expanded_type' => true, 'currency_choices' => true, 'currency_disabled' => true]);
        foreach (['minimumSafeBalance', 'bankName', 'iban', 'bic', 'corrAccount', 'location', 'responsiblePerson', 'provider', 'walletId'] as $field) {
            $form->remove($field);
        }
        $form->handleRequest($request);
        $bankIntegration = ['provider' => $account->getBankProviderCode() ?? '', 'external_account_id' => $account->getBankExternalAccountId() ?? '', 'number' => $account->getBankAccountNumber() ?? '', 'auth' => ''];
        $existingAuth = $account->getBankAuth();
        if (null !== $existingAuth) {
            $encodedAuth = json_encode($existingAuth, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
            $bankIntegration['auth'] = false !== $encodedAuth ? $encodedAuth : '';
        }
        $clearBankLinkRequested = false;
        $authPayload = null;
        $authProvided = false;
        $bankIntegrationSubmitted = $form->isSubmitted() && $request->request->has('bank_provider');
        if ($bankIntegrationSubmitted) {
            $bankIntegration['provider'] = trim((string) $request->request->get('bank_provider', ''));
            $bankIntegration['external_account_id'] = trim((string) $request->request->get('bank_external_account_id', ''));
            $bankIntegration['number'] = trim((string) $request->request->get('bank_number', ''));
            $bankIntegration['auth'] = (string) $request->request->get('bank_auth', '');
            $clearBankLinkRequested = $request->request->has('clear_bank_link');
            if (!$clearBankLinkRequested) {
                if ('' !== $bankIntegration['provider'] && '' === $bankIntegration['external_account_id']) {
                    $form->addError(new FormError('Для выбранного провайдера необходимо указать внешний ID счёта.'));
                }
                if ('' === $bankIntegration['provider'] && '' !== $bankIntegration['external_account_id']) {
                    $form->addError(new FormError('Укажите провайдера для внешнего ID счёта.'));
                }
                $authRaw = trim($bankIntegration['auth']);
                if ('' !== $authRaw) {
                    try {
                        $authPayload = json_decode($authRaw, true, 512, \JSON_THROW_ON_ERROR);
                        if (!is_array($authPayload)) {
                            $form->addError(new FormError('Поле авторизации должно содержать объект JSON.'));
                            $authPayload = null;
                        } else {
                            $authProvided = true;
                        }
                    } catch (\JsonException) {
                        $form->addError(new FormError('Поле авторизации должно содержать корректный JSON.'));
                    }
                }
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            ($this->saveMoneyAccount)($account, $bankIntegrationSubmitted ? new \App\Cash\Application\DTO\MoneyAccountBankLinkInput($bankIntegration['provider'], $bankIntegration['external_account_id'], $bankIntegration['number'], $bankIntegration['auth'], $clearBankLinkRequested, $authProvided, $authPayload) : null);

            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/edit.html.twig', ['form' => $form->createView(), 'account' => $account, 'bankIntegration' => $bankIntegration, 'bankCursor' => $account->getBankCursor()]);
    }
}
