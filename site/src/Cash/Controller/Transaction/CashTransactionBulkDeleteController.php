<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\BulkDeleteCashTransactionsAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions/bulk-delete', name: 'cash_transaction_bulk_delete', methods: ['POST'])]
final class CashTransactionBulkDeleteController extends AbstractController
{
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(
        Request $request,
        ActiveCompanyService $companyService,
        AuditContextProvider $auditContextProvider,
        BulkDeleteCashTransactionsAction $action,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid(
            'cash_transaction_bulk_delete',
            (string) $request->request->get('_token'),
        )) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transaction_index', $request->query->all());
        }

        $transactionIds = $request->request->all()['transaction_ids'] ?? [];
        if (!\is_array($transactionIds)) {
            $transactionIds = [];
        }

        try {
            $deletedCount = $action(
                $companyService->getActiveCompany(),
                $transactionIds,
                $auditContextProvider->getActorUserId(),
            );
            $this->addFlash('success', sprintf('Удалено транзакций: %d.', $deletedCount));
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('cash_transaction_index', $request->query->all());
    }
}
