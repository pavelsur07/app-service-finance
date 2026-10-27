<?php

declare(strict_types=1);

namespace App\BankSync\Controller;

use App\BankSync\Application\BankAccountLinkChoices;
use App\BankSync\Application\LinkBankAccountAction;
use App\BankSync\Application\UnlinkBankAccountAction;
use App\BankSync\Entity\BankAccount;
use App\BankSync\Exception\BankAccountNotFoundException;
use App\BankSync\Exception\MoneyAccountAlreadyLinkedException;
use App\BankSync\Exception\MoneyAccountNotLinkableException;
use App\BankSync\Form\BankAccountLinkType;
use App\BankSync\Repository\BankAccountRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountLinkController extends AbstractController
{
    public const UNLINK_CSRF_PREFIX = 'bank_account_unlink_';

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly BankAccountRepository $accounts,
        private readonly BankAccountLinkChoices $linkChoices,
        private readonly LinkBankAccountAction $linkAccount,
        private readonly UnlinkBankAccountAction $unlinkAccount,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('/bank-connections/{id}/accounts/{accountId}/link', name: 'bank_sync_account_link', requirements: ['id' => Requirement::UUID, 'accountId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function link(Request $request, string $id, string $accountId): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $account = $this->requireAccount($companyId, $id, $accountId);

        $form = $this->forms->createNamed(
            'link_'.str_replace('-', '', $account->getId()),
            BankAccountLinkType::class,
            null,
            ['link_choices' => $this->linkChoices->forAccounts($companyId, [$account])[$account->getId()]['choices']],
        );
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Выберите денежный счёт из списка.');

            return $this->redirectToAccounts($id);
        }
        /** @var array{moneyAccountId: string} $data */
        $data = $form->getData();

        try {
            ($this->linkAccount)($companyId, $account->getId(), $data['moneyAccountId']);
            $this->addFlash('success', 'Счёт банка привязан: выписка будет загружаться в выбранный денежный счёт.');
        } catch (MoneyAccountAlreadyLinkedException) {
            $this->addFlash('danger', 'Этот денежный счёт уже получает выписку из другого счёта банка.');
        } catch (MoneyAccountNotLinkableException $exception) {
            $this->addFlash('danger', MoneyAccountNotLinkableException::CURRENCY === $exception->reason
                ? 'Валюта денежного счёта не совпадает с валютой счёта банка.'
                : 'Денежный счёт не найден или неактивен.');
        } catch (BankAccountNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToAccounts($id);
    }

    #[Route('/bank-connections/{id}/accounts/{accountId}/unlink', name: 'bank_sync_account_unlink', requirements: ['id' => Requirement::UUID, 'accountId' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function unlink(Request $request, string $id, string $accountId): Response
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $account = $this->requireAccount($companyId, $id, $accountId);
        if (!$this->isCsrfTokenValid(self::UNLINK_CSRF_PREFIX.$account->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            ($this->unlinkAccount)($companyId, $account->getId());
            $this->addFlash('success', 'Счёт банка отвязан: выписка по нему больше не загружается.');
        } catch (BankAccountNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToAccounts($id);
    }

    /**
     * Счёт банка своей компании и именно этого подключения; иначе 404 до CSRF.
     */
    private function requireAccount(string $companyId, string $connectionId, string $accountId): BankAccount
    {
        $account = $this->accounts->findOneByIdAndCompanyId($accountId, $companyId);
        if (null === $account || $account->getConnectionId() !== $connectionId) {
            throw $this->createNotFoundException();
        }

        return $account;
    }

    private function redirectToAccounts(string $connectionId): Response
    {
        return $this->redirectToRoute('bank_sync_connection_accounts', ['id' => $connectionId]);
    }
}
