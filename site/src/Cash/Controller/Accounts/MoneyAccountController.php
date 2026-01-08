<?php

namespace App\Cash\Controller\Accounts;

use App\Cash\Form\Accounts\MoneyAccountType as MoneyAccountFormType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Entity\MoneyAccount;
use App\Enum\MoneyAccountType;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/accounts')]
class MoneyAccountController extends AbstractController
{
    public function __construct(private ActiveCompanyService $activeCompanyService)
    {
    }

    #[Route('/', name: 'money_account_index', methods: ['GET'])]
    public function index(MoneyAccountRepository $repository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $accounts = $repository->findBy(['company' => $company]);

        return $this->render('profile/money_account/index.html.twig', [
            'accounts' => $accounts,
        ]);
    }

    #[Route('/new', name: 'money_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $account = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: MoneyAccountType::BANK,
            name: '',
            currency: 'RUB'
        );

        $form = $this->createForm(MoneyAccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($account);
            $em->flush();

            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'money_account_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        MoneyAccount $account,
        EntityManagerInterface $em,
        AccountBalanceService $balanceService,
    ): Response {
        $form = $this->createForm(MoneyAccountFormType::class, $account);
        $form->handleRequest($request);

        $bankIntegration = [
            'provider' => $account->getBankProviderCode() ?? '',
            'external_account_id' => $account->getBankExternalAccountId() ?? '',
            'number' => $account->getBankAccountNumber() ?? '',
            'auth' => '',
        ];

        $existingAuth = $account->getBankAuth();
        if (null !== $existingAuth) {
            $encodedAuth = json_encode($existingAuth, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
            $bankIntegration['auth'] = false !== $encodedAuth ? $encodedAuth : '';
        }

        $clearBankLinkRequested = false;
        $authPayload = null;
        $authProvided = false;

        if ($form->isSubmitted()) {
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
            if ($clearBankLinkRequested) {
                $account->clearBankLink();
                $account->setBankCursor(null);
            } else {
                $shouldLinkBank = '' !== $bankIntegration['provider'] && '' !== $bankIntegration['external_account_id'];

                if ($shouldLinkBank) {
                    $account->setBankLink(
                        $bankIntegration['provider'],
                        $bankIntegration['external_account_id'],
                        '' !== $bankIntegration['number'] ? $bankIntegration['number'] : null
                    );
                }

                $bankMeta = $account->getBankMeta() ?? [];

                if ('' !== $bankIntegration['number']) {
                    $bankMeta['number'] = $bankIntegration['number'];
                } else {
                    unset($bankMeta['number']);
                }

                if ($authProvided) {
                    $bankMeta['auth'] = $authPayload;
                } elseif (array_key_exists('auth', $bankMeta) && '' === trim($bankIntegration['auth'])) {
                    unset($bankMeta['auth']);
                }

                if (!empty($bankMeta)) {
                    $account->setBankMeta($bankMeta);
                } else {
                    $account->setBankMeta(null);
                }
            }

            $em->flush();

            $company = $account->getCompany();
            $balanceService->recalculateDailyRange(
                $company,
                $account,
                $account->getOpeningBalanceDate(),
                new \DateTimeImmutable('today')
            );
            $todayBalance = $balanceService->getBalanceOnDate(
                $company,
                $account,
                new \DateTimeImmutable('today')
            );
            $account->setCurrentBalance($todayBalance->closing);
            $em->flush();

            return $this->redirectToRoute('money_account_index');
        }

        return $this->render('profile/money_account/edit.html.twig', [
            'form' => $form->createView(),
            'account' => $account,
            'bankIntegration' => $bankIntegration,
            'bankCursor' => $account->getBankCursor(),
        ]);
    }
}
