<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionDTO;
use App\Cash\Application\Service\Transaction\CashTransactionService;
use App\Cash\Application\Transaction\UpdateCashTransactionAction;
use App\Cash\Form\Transaction\CashTransactionType;
use App\Cash\Repository\Transaction\CashTransactionRepository;
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
#[Route('/finance/cash-transactions')]
final class CashTransactionEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly CashTransactionService $service,
        private readonly UpdateCashTransactionAction $updateTransaction,
        private readonly \App\Cash\Http\CashTransactionPage $page,
    ) {
    }

    #[Route('/{id}/edit', name: 'cash_transaction_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $tx = $this->txRepo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        try {
            $this->service->assertStandaloneTransaction($tx);
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }
        $dto = new CashTransactionDTO();
        $dto->occurredAt = $tx->getOccurredAt();
        $dto->amount = $tx->getAmount();
        $dto->direction = $tx->getDirection();
        $dto->description = $tx->getDescription();
        $dto->responsibilityCenterId = $tx->getResponsibilityCenterId();
        // Архивный контрагент остаётся в списке выбора, пока он выбран у операции.
        $dto->counterpartyId = $tx->getCounterparty()?->getId();
        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company]);
        $form->get('moneyAccount')->setData($tx->getMoneyAccount());
        $form->get('cashflowCategory')->setData($tx->getCashflowCategory());
        $form->get('projectDirection')->setData($tx->getProjectDirection());
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    ($this->updateTransaction)($tx, $data);
                    $this->addFlash('success', 'Транзакция обновлена');

                    return $this->redirectToRoute('cash_transaction_index', array_filter($request->query->all(), static fn ($value) => '' !== $value));
                } catch (\App\Cash\Exception\CashTransactionMultiSplitException|\DomainException $e) {
                    // Период закрыт — показать ошибку и остаться на форме
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }
        // «Разнесено в ОПиУ» — в Money по суммам операций документов, как и лимит создания документа.
        $summary = $this->service->pnlSummary($tx);

        return $this->render('transaction/edit.html.twig', ['form' => $form->createView(), 'tx' => $tx, 'canCreatePnlDocument' => $this->page->canCreatePnlDocument($tx), 'pnlDocuments' => $this->page->getDocumentsForTransaction($tx), 'pnlAllocated' => $summary['allocated'], 'pnlUnallocated' => $summary['unallocated']], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
