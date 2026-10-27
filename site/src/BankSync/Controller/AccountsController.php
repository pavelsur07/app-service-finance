<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\BankAccountLinkChoices;
use App\BankSync\Form\BankAccountLinkType;
use App\BankSync\Repository\BankAccountRepository;
use App\BankSync\Repository\BankConnectionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Счета банка» подключения: что банк вернул при последней загрузке и куда каждый счёт
 * грузится. Список обновляет загрузка («Запустить импорт сейчас»).
 */
#[IsGranted('ROLE_USER')]
final class AccountsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankConnectionRepository $connections,
        private readonly BankAccountRepository $accounts,
        private readonly BankAccountLinkChoices $linkChoices,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('/bank-connections/{id}/accounts', name: 'bank_sync_connection_accounts', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $connection = $this->connections->findOneByIdAndCompanyId($id, $companyId)
            ?? throw $this->createNotFoundException();

        $accounts = $this->accounts->findByConnection($companyId, $connection->getId());
        $choices = $this->linkChoices->forAccounts($companyId, $accounts);
        $forms = [];
        $moneyAccountNames = [];
        foreach ($accounts as $account) {
            $accountChoices = $choices[$account->getId()];
            $forms[$account->getId()] = $this->forms->createNamed(
                'link_'.str_replace('-', '', $account->getId()),
                BankAccountLinkType::class,
                ['moneyAccountId' => $accountChoices['suggested']],
                [
                    'link_choices' => $accountChoices['choices'],
                    'action' => $this->generateUrl('bank_sync_account_link', ['id' => $connection->getId(), 'accountId' => $account->getId()]),
                ],
            )->createView();
            $moneyAccountNames += array_flip($accountChoices['choices']);
        }

        return $this->render('@BankSync/account/index.html.twig', [
            'connection' => $connection,
            'accounts' => $accounts,
            'forms' => $forms,
            // Привязанный счёт, выпавший из вариантов, — неактивный: смену валюты Cash запрещает.
            'money_account_names' => $moneyAccountNames,
        ]);
    }
}
