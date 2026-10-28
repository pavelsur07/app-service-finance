<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\CreatePnlDocumentFromTransactionAction;
use App\Cash\Application\DeleteCashTransactionAction;
use App\Cash\Application\RestoreCashTransactionAction;
use App\Cash\Application\UpdateCashTransactionAction;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\DTO\CashTransactionFilters;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Cash\Form\Transaction\CashTransactionType;
use App\Cash\Message\EnqueueAutoRulesForRange;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Company\Facade\CounterpartyFacade;
use App\Company\Security\ModuleAccess;
use App\Finance\Entity\Document;
use App\Finance\Exception\DocumentForeignReferenceException;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\CompanyContextService;
use Doctrine\ORM\Exception\ORMException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
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
        CounterpartyFacade $counterpartyFacade,
        CashTransferRepository $transferRepository,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        try {
            $filters = CashTransactionFilters::fromQuery($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transaction_index');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $pager = $txRepo->paginateByCompanyWithFilters($company, $filters, $page, $limit);

        $transactions = iterator_to_array($pager->getCurrentPageResults());
        $txRepo->warmSplits($transactions);
        $transactionIds = array_map(
            static fn (CashTransaction $transaction): string => (string) $transaction->getId(),
            $transactions,
        );
        $transferByTransactionId = $this->mapTransfersToLegIds(
            $transferRepository->findByTransactionIdsAndCompanyId(
                $transactionIds,
                $company->getId(),
            ),
        );

        $accounts = $accountRepo->findBy(['company' => $company]);
        $categories = $categoryRepo->findTreeByCompany($company);
        // Неархивные по показанному названию плюс выбранный в фильтре (даже архивный):
        // шаблону нужны только они — список и подпись выбранного.
        $counterparties = $counterpartyFacade->getSelectable((string) $company->getId(), $filters['counterpartyId'] ?? null);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filters' => $filters,
            'accounts' => $accounts,
            'categories' => $categories,
            'counterparties' => $counterparties,
            'currencies' => FiatCurrency::cases(),
            'pager' => $pager,
            'transferByTransactionId' => $transferByTransactionId,
            'hasStandaloneTransactions' => (bool) array_diff(
                $transactionIds,
                array_keys($transferByTransactionId),
            ),
        ]);
    }

    #[Route('/auto-rule-apply', name: 'cash_transaction_auto_rule_apply_page', methods: ['GET'])]
    public function autoRuleApplyPage(): Response
    {
        $today = new \DateTimeImmutable('today');
        $from = $today->setDate((int) $today->format('Y'), 1, 1);

        return $this->render('transaction/auto_rule_apply.html.twig', [
            'from' => $from,
            'to' => $today,
        ]);
    }

    #[Route('/auto-rule-apply', name: 'cash_transaction_auto_rule_apply_enqueue', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function autoRuleApplyEnqueue(
        Request $request,
        MessageBusInterface $bus,
        AuditContextProvider $auditContextProvider,
    ): RedirectResponse {
        $company = $this->companyService->getActiveCompany();

        if (!$this->isCsrfTokenValid('cash_transaction_auto_rule_apply_enqueue', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }

        $mode = CashTransactionAutoRuleApplyMode::tryFrom((string) $request->request->get(
            'mode',
            CashTransactionAutoRuleApplyMode::SAFE->value,
        ));
        if (null === $mode) {
            $this->addFlash('danger', 'Выберите режим применения автоправил.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }

        $initiatedByUserId = $auditContextProvider->getActorUserId();
        if ($mode->replacesAutoAssigned()
            && ('1' !== $request->request->get('confirm_replace') || null === $initiatedByUserId)) {
            $this->addFlash('danger', 'Подтвердите небезопасную замену значений, назначенных автоправилами.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }

        $today = new \DateTimeImmutable('today');
        $from = $today->setDate((int) $today->format('Y'), 1, 1);

        $bus->dispatch(new EnqueueAutoRulesForRange(
            $company->getId(),
            $from,
            $today,
            null,
            Uuid::uuid7()->toString(),
            $mode,
            $initiatedByUserId,
        ));

        $this->addFlash('success', sprintf(
            'Автоправила поставлены в очередь в режиме «%s» за период %s — %s.',
            $mode->replacesAutoAssigned() ? 'Небезопасный' : 'Безопасный',
            $from->format('d.m.Y'),
            $today->format('d.m.Y'),
        ));

        return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
    }

    #[Route('/deleted', name: 'cash_transaction_deleted_index', methods: ['GET'])]
    public function deletedIndex(
        Request $request,
        CashTransactionRepository $txRepo,
        CashTransferRepository $transferRepository,
        CompanyContextService $companyContextService,
    ): Response {
        $companyId = $companyContextService->getCompanyId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;

        $pager = $txRepo->paginateDeletedByCompany($companyId, $page, $perPage);
        $transactions = iterator_to_array($pager->getCurrentPageResults());
        $txRepo->warmSplits($transactions);
        $transferByTransactionId = $this->mapTransfersToLegIds(
            $transferRepository->findByTransactionIdsAndCompanyId(
                array_map(static fn (CashTransaction $transaction): string => (string) $transaction->getId(), $transactions),
                $companyId,
            ),
        );

        return $this->render('cash/transaction/deleted_index.html.twig', [
            'pager' => $pager,
            'transactions' => $transactions,
            'transferByTransactionId' => $transferByTransactionId,
        ]);
    }

    #[Route('/{id}', name: 'cash_transaction_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, CashTransactionRepository $txRepo, CashTransferRepository $transferRepository): Response
    {
        $company = $this->companyService->getActiveCompany();
        $tx = $txRepo->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();

        $transfer = $transferRepository->findOneByTransactionAndCompanyId($tx, $company->getId());

        return $this->render('transaction/show.html.twig', [
            'tx' => $tx,
            'transfer' => $transfer,
            'canCreatePnlDocument' => null === $transfer && $this->canCreatePnlDocument($tx),
            'pnlDocuments' => $this->getDocumentsForTransaction($tx),
        ]);
    }

    #[Route('/{id}/create-pnl-document', name: 'cash_transaction_create_pnl_document', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function createPnlDocument(
        Request $request,
        string $id,
        CashTransactionRepository $txRepo,
        CreatePnlDocumentFromTransactionAction $createPnlDocument,
        LoggerInterface $logger,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $tx = $txRepo->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('create_pnl_document'.$tx->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }

        if (!$this->canCreatePnlDocument($tx)) {
            $this->addFlash('danger', 'Для этой транзакции нельзя создать документ ОПиУ.');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }

        try {
            $document = $createPnlDocument($tx);
            $this->addFlash('success', 'Документ ОПиУ создан.');

            return $this->redirectToRoute('document_edit', ['id' => $document->getId()]);
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (DocumentForeignReferenceException $e) {
            // Испорченные данные: статья ДДС, контрагент или проект операции ссылаются на
            // справочник другой компании. Документ не создаётся — иначе чужое название попало
            // бы в ОПиУ компании; это инцидент, его чинят данными.
            $logger->error('P&L document from cash transaction refused: foreign reference', [
                'companyId' => (string) $company->getId(),
                'cashTransactionId' => $tx->getId(),
                'reference' => $e->getReference(),
                'referenceId' => $e->getReferenceId(),
            ]);
            $this->addFlash('danger', 'Документ ОПиУ не создан: в операции ДДС или её статье указан справочник другой компании. Мы уже знаем об ошибке.');
        }

        return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
    }

    private function canCreatePnlDocument(CashTransaction $transaction): bool
    {
        $category = $transaction->getCashflowCategory();

        return $transaction->getRemainingMoney()->isPositive()
            && $category instanceof CashflowCategory
            && $category->isAllowPlDocument();
    }

    /**
     * @return Document[]
     */
    private function getDocumentsForTransaction(CashTransaction $transaction): array
    {
        $documents = array_values(array_filter(
            $transaction->getDocuments()->toArray(),
            static fn (Document $document): bool => !$document->isDeleted(),
        ));

        usort(
            $documents,
            static fn (Document $a, Document $b): int => $b->getDate() <=> $a->getDate(),
        );

        return $documents;
    }

    /**
     * @param list<CashTransfer> $transfers
     *
     * @return array<string, CashTransfer>
     */
    private function mapTransfersToLegIds(array $transfers): array
    {
        $map = [];
        foreach ($transfers as $transfer) {
            $map[(string) $transfer->getSourceTransaction()->getId()] = $transfer;
            $map[(string) $transfer->getTargetTransaction()->getId()] = $transfer;
        }

        return $map;
    }

    /**
     * @throws ORMException
     */
    #[Route('/new', name: 'cash_transaction_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        AddCashTransactionAction $addTransaction,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $dto = new CashTransactionDTO();
        $dto->occurredAt = new \DateTimeImmutable('today');
        // Ключ повтора выдаётся при показе формы: двойная отправка не создаёт вторую транзакцию.
        $dto->idempotencyKey = Uuid::uuid7()->toString();

        $form = $this->createForm(CashTransactionType::class, $dto, ['company' => $company, 'idempotency_key' => true]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                /** @var CashTransactionDTO $data */
                $data = $form->getData();
                try {
                    $addTransaction($data);
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
            }
        }

        // Невалидная форма — та же страница с ошибками у полей и 422, а не сырой JSON.
        return $this->render('transaction/new.html.twig', [
            'form' => $form->createView(),
            'tx' => null,
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/edit', name: 'cash_transaction_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        string $id,
        CashTransactionRepository $txRepo,
        CashTransactionService $service,
        UpdateCashTransactionAction $updateTransaction,
    ): Response {
        $company = $this->companyService->getActiveCompany();

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $tx = $txRepo->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();
        try {
            $service->assertStandaloneTransaction($tx);
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
                    $updateTransaction($tx, $data);
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

        // «Разнесено в ОПиУ» — в Money по суммам операций документов, как и лимит создания документа.
        $allocated = $tx->recalculateAllocatedAmount()->getAllocatedMoney();

        return $this->render('transaction/edit.html.twig', [
            'form' => $form->createView(),
            'tx' => $tx,
            'canCreatePnlDocument' => $this->canCreatePnlDocument($tx),
            'pnlDocuments' => $this->getDocumentsForTransaction($tx),
            'pnlAllocated' => $allocated->toDecimalString(),
            'pnlUnallocated' => Money::fromString($tx->getAmount(), $tx->getCurrency())->subtract($allocated)->toDecimalString(),
        ], new Response(status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/restore', name: 'cash_transaction_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function restore(
        Request $request,
        string $id,
        CashTransactionRepository $txRepo,
        RestoreCashTransactionAction $restoreTransaction,
    ): Response {
        $company = $this->companyService->getActiveCompany();
        $tx = $txRepo->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('tx_restore'.$tx->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен');

            return $this->redirectToRoute('cash_transaction_deleted_index');
        }

        try {
            $restoreTransaction($tx);
            $this->addFlash('success', 'Транзакция восстановлена');
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('cash_transaction_deleted_index');
    }

    #[Route('/{id}/delete', name: 'cash_transaction_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(Request $request, string $id, CashTransactionRepository $txRepo, DeleteCashTransactionAction $deleteTransaction): Response
    {
        $company = $this->companyService->getActiveCompany();
        $tx = $txRepo->findOneByIdAndCompanyId($id, (string) $company->getId())
            ?? throw $this->createNotFoundException();

        if ($this->isCsrfTokenValid('tx_delete'.$tx->getId(), $request->request->get('_token'))) {
            try {
                $deleteTransaction($tx);
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
