<?php

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Form\Transaction\CashTransactionType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Cash\Service\Transaction\CashTransactionToDocumentService;
use App\DTO\CashTransactionDTO;
use App\Entity\Document;
use App\Repository\CounterpartyRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/cash-transactions')]
class CashTransactionController extends AbstractController
{
    public function __construct(private ActiveCompanyService $companyService)
    {
    }

    #[Route('/', name: 'cash_transaction_index', methods: ['GET'])]
    public function index(
        Request $request,
        CashTransactionRepository $txRepo,
        MoneyAccountRepository $accountRepo,
        CashflowCategoryRepository $categoryRepo,
        CounterpartyRepository $counterpartyRepo,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        $filters = [
            'dateFrom' => $request->query->get('dateFrom'),
            'dateTo' => $request->query->get('dateTo'),
            'accountId' => $request->query->get('accountId'),
            'categoryId' => $request->query->get('categoryId'),
            'counterpartyId' => $request->query->get('counterpartyId'),
            'direction' => $request->query->get('direction'),
            'amountMin' => $request->query->get('amountMin'),
            'amountMax' => $request->query->get('amountMax'),
            'q' => $request->query->get('q'),
        ];

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $pager = $txRepo->paginateByCompanyWithFilters($company, $filters, $page, $limit);

        $transactions = iterator_to_array($pager->getCurrentPageResults());

        $accounts = $accountRepo->findBy(['company' => $company]);
        $categories = $categoryRepo->findTreeByCompany($company);
        $counterparties = $counterpartyRepo->findBy(['company' => $company], ['name' => 'ASC']);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filters' => $filters,
            'accounts' => $accounts,
            'categories' => $categories,
            'counterparties' => $counterparties,
            'pager' => $pager,
        ]);
    }

    #[Route('/deleted', name: 'cash_transaction_deleted_index', methods: ['GET'])]
    public function deletedIndex(
        Request $request,
        CashTransactionRepository $txRepo,
        CompanyContextService $companyContextService,
    ): Response {
        $companyId = $companyContextService->getCompanyId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;

        $pager = $txRepo->paginateDeletedByCompany($companyId, $page, $perPage);

        return $this->render('cash/transaction/deleted_index.html.twig', [
            'pager' => $pager,
            'transactions' => iterator_to_array($pager->getCurrentPageResults()),
        ]);
    }

    #[Route('/{id}', name: 'cash_transaction_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(CashTransaction $tx): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        return $this->render('transaction/show.html.twig', [
            'tx' => $tx,
            'canCreatePnlDocument' => $this->canCreatePnlDocument($tx),
            'pnlDocuments' => $this->getDocumentsForTransaction($tx),
        ]);
    }

    #[Route('/{id}/create-pnl-document', name: 'cash_transaction_create_pnl_document', methods: ['POST'])]
    public function createPnlDocument(
        Request $request,
        CashTransaction $tx,
        CashTransactionToDocumentService $operationFactory,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('create_pnl_document'.$tx->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }

        if (!$this->canCreatePnlDocument($tx)) {
            $this->addFlash('danger', 'Для этой транзакции нельзя создать документ ОПиУ.');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }

        try {
            $document = $operationFactory->createPnlDocumentFromTransaction($tx);
            $this->addFlash('success', 'Документ ОПиУ создан.');

            return $this->redirectToRoute('document_edit', ['id' => $document->getId()]);
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
    }

    private function canCreatePnlDocument(CashTransaction $transaction): bool
    {
        $category = $transaction->getCashflowCategory();

        return $transaction->getRemainingAmount() > 0
            && $category instanceof CashflowCategory
            && $category->isAllowPlDocument();
    }

    /**
     * @return Document[]
     */
    private function getDocumentsForTransaction(CashTransaction $transaction): array
    {
        $documents = $transaction->getDocuments()->toArray();

        usort(
            $documents,
            static fn (Document $a, Document $b): int => $b->getDate() <=> $a->getDate(),
        );

        return $documents;
    }

    /**
     * @throws ORMException
     */
    #[Route('/new', name: 'cash_transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CashTransactionService $service,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $dto = new CashTransactionDTO();
        $dto->occurredAt = new \DateTimeImmutable('today');

        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    $service->add($data);
                    $this->addFlash('success', 'Транзакция добавлена');

                    return $this->redirectToRoute('cash_transaction_index', array_filter(
                        $request->query->all(),
                        static fn ($value) => null !== $value && '' !== $value
                    ));
                } catch (\DomainException $e) {
                    // Период закрыт — показать ошибку пользователю
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                    // остаёмся на форме (без редиректа)
                }
            } else {
                return $this->json($form->getErrors(true));
            }
        }

        return $this->render('transaction/new.html.twig', [
            'form' => $form->createView(),
            'tx' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'cash_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CashTransaction $tx,
        CashTransactionService $service,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $dto = new CashTransactionDTO();
        $dto->occurredAt = $tx->getOccurredAt();
        $dto->amount = $tx->getAmount();
        $dto->direction = $tx->getDirection();
        $dto->description = $tx->getDescription();

        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company]);

        $form->get('moneyAccount')->setData($tx->getMoneyAccount());
        $form->get('cashflowCategory')->setData($tx->getCashflowCategory());
        $form->get('counterparty')->setData($tx->getCounterparty());
        $form->get('projectDirection')->setData($tx->getProjectDirection());

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    $service->update($tx, $data);
                    $this->addFlash('success', 'Транзакция обновлена');

                    return $this->redirectToRoute('cash_transaction_index', array_filter(
                        $request->query->all(),
                        static fn ($value) => null !== $value && '' !== $value
                    ));
                } catch (\DomainException $e) {
                    // Период закрыт — показать ошибку и остаться на форме
                    $form->addError(new FormError($e->getMessage()));
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        }

        return $this->render('transaction/edit.html.twig', [
            'form' => $form->createView(),
            'tx' => $tx,
            'canCreatePnlDocument' => $this->canCreatePnlDocument($tx),
            'pnlDocuments' => $this->getDocumentsForTransaction($tx),
        ]);
    }

    #[Route('/{id}/restore', name: 'cash_transaction_restore', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function restore(
        Request $request,
        int $id,
        CashTransactionRepository $txRepo,
        CashTransactionService $service,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $tx = $txRepo->find($id);
        if (!$tx || $tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('tx_restore'.$tx->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен');

            return $this->redirectToRoute('cash_transaction_deleted_index');
        }

        $service->restore($tx);
        $this->addFlash('success', 'Транзакция восстановлена');

        return $this->redirectToRoute('cash_transaction_deleted_index');
    }

    #[Route('/{id}/delete', name: 'cash_transaction_delete', methods: ['POST'])]
    public function delete(Request $request, CashTransaction $tx, CashTransactionService $service): Response
    {
        $company = $this->companyService->getActiveCompany();
        if ($tx->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('tx_delete'.$tx->getId(), $request->request->get('_token'))) {
            try {
                $service->delete($tx);
                $this->addFlash('success', 'Транзакция удалена');
            } catch (\DomainException $e) {
                // Период закрыт — показать сообщение и вернуть на список
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->addFlash('danger', 'Неверный CSRF токен');
        }

        return $this->redirectToRoute('cash_transaction_index');
    }
}
