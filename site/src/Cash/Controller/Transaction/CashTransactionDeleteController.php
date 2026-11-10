<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DeleteCashTransactionAction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly DeleteCashTransactionAction $deleteTransaction,
    ) {
    }

    #[Route('/{id}/delete', name: 'cash_transaction_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $tx = $this->txRepo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        if ($this->isCsrfTokenValid('tx_delete'.$tx->getId(), (string) $request->request->get('_token'))) {
            try {
                ($this->deleteTransaction)($tx);
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
