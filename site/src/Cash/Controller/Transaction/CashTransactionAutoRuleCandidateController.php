<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route(
    '/cash-transaction-auto-rules/candidates',
    name: 'cash_transaction_auto_rule_candidates',
    methods: ['GET'],
)]
final class CashTransactionAutoRuleCandidateController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CashTransactionAutoRuleCandidateQuery $candidateQuery,
    ) {
    }

    public function __invoke(): Response
    {
        $today = new \DateTimeImmutable('today');
        $from = $today->modify(sprintf('-%d days', CashTransactionAutoRuleCandidateQuery::PERIOD_DAYS));
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        return $this->render('cash_transaction_auto_rule/candidates.html.twig', [
            'candidates' => $this->candidateQuery->findForCompany($companyId, $from, $today),
            'dateFrom' => $from,
            'dateTo' => $today,
            'minSamples' => CashTransactionAutoRuleCandidateQuery::MIN_SAMPLES,
            'minDistinctDates' => CashTransactionAutoRuleCandidateQuery::MIN_DISTINCT_DATES,
            'maxCandidates' => CashTransactionAutoRuleCandidateQuery::MAX_CANDIDATES,
        ]);
    }
}
