<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Shared\Repository\AuditLogRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/transactions')]
final class CashTransactionAuditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly AuditLogRepository $auditLogRepository,
    ) {
    }

    #[Route('/audit', name: 'cash_transaction_audit_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = (string) $this->companyService->getActiveCompany()->getId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $auditPager = $this->auditLogRepository->paginateForEntityClass($companyId, CashTransaction::class, $page, $perPage);
        $auditLogs = iterator_to_array($auditPager->getCurrentPageResults());

        return $this->render('cash/transaction/audit_index.html.twig', ['auditPager' => $auditPager, 'auditLogs' => $auditLogs]);
    }
}
