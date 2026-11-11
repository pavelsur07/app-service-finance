<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\CreatePnlDocumentFromTransactionAction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Security\ModuleAccess;
use App\Finance\Exception\DocumentForeignReferenceException;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionCreatePnlDocumentController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly \App\Cash\Http\CashTransactionPage $page,
        private readonly CreatePnlDocumentFromTransactionAction $createPnlDocument,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/{id}/create-pnl-document', name: 'cash_transaction_create_pnl_document', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $tx = $this->txRepo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('create_pnl_document'.$tx->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }
        if (!$this->page->canCreatePnlDocument($tx)) {
            $this->addFlash('danger', 'Для этой транзакции нельзя создать документ ОПиУ.');

            return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
        }
        try {
            $document = ($this->createPnlDocument)($tx);
            $this->addFlash('success', 'Документ ОПиУ создан.');

            return $this->redirectToRoute('document_edit', ['id' => $document->getId()]);
        } catch (\App\Cash\Exception\CashTransactionMultiSplitException|\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (DocumentForeignReferenceException $e) {
            // Испорченные данные: статья ДДС, контрагент или проект операции ссылаются на
            // справочник другой компании. Документ не создаётся — иначе чужое название попало
            // бы в ОПиУ компании; это инцидент, его чинят данными.
            $this->logger->error('P&L document from cash transaction refused: foreign reference', ['companyId' => (string) $company->getId(), 'cashTransactionId' => $tx->getId(), 'reference' => $e->getReference(), 'referenceId' => $e->getReferenceId()]);
            $this->addFlash('danger', 'Документ ОПиУ не создан: в операции ДДС или её статье указан справочник другой компании. Мы уже знаем об ошибке.');
        }

        return $this->redirectToRoute('cash_transaction_show', ['id' => $tx->getId()]);
    }
}
