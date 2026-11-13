<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleService;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleMatchController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly CashTransactionAutoRuleService $autoRuleService,
    ) {
    }

    #[Route('/match/{transactionId}', name: 'cash_transaction_auto_rule_match_one', methods: ['GET'])]
    public function __invoke(string $transactionId): Response
    {
        $company = $this->companyService->getActiveCompany();
        /** @var CashTransaction|null $t */
        $t = $this->txRepo->findOneByIdAndCompanyId($transactionId, (string) $company->getId());
        if (!$t) {
            throw $this->createNotFoundException();
        }

        return $this->render('cash_transaction_auto_rule/_auto_rule_modal_body.html.twig', $this->autoRuleService->previewTransaction($t));
    }
}
