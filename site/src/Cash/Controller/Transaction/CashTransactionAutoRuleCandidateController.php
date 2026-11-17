<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Infrastructure\Query\CashRulePhraseQuery;
use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly CashCategorizationQuery $categorizationQuery,
        private readonly AiTaskFacadeInterface $gateway,
        private readonly CashRulePhraseQuery $phrases,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');
        $from = $today->modify(sprintf('-%d days', CashTransactionAutoRuleCandidateQuery::PERIOD_DAYS));
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();

        $budget = $this->gateway->isEnabledForCompany($companyId) ? $this->gateway->budgetFor($companyId) : null;
        $available = null !== $budget && !$budget->exceeded();
        $task = $request->query->getString('search');
        $review = '' !== $task && Uuid::isValid($task) ? $this->phrases->review($companyId, $task) : null;
        if ('' !== $task && null === $review) {
            throw $this->createNotFoundException();
        }

        return $this->render('cash_transaction_auto_rule/candidates.html.twig', [
            'companyId' => $companyId,
            'phraseAvailable' => $available,
            'phraseTargets' => $available ? $this->candidateQuery->phraseTargets($companyId, $budget->lookbackDays) : [],
            'phraseSearches' => $this->phrases->searches($companyId),
            'phraseReview' => $review, 'selectedSearch' => $task,
            'candidates' => $this->candidateQuery->findForCompany($companyId, $from, $today),
            'aiProposals' => $this->categorizationQuery->findRuleProposals($companyId),
            'aiProposalMinConfirmed' => CashCategorizationQuery::RULE_PROPOSAL_MIN_CONFIRMED,
            'dateFrom' => $from,
            'dateTo' => $today,
            'minSamples' => CashTransactionAutoRuleCandidateQuery::MIN_SAMPLES,
            'minDistinctDates' => CashTransactionAutoRuleCandidateQuery::MIN_DISTINCT_DATES,
            'maxCandidates' => CashTransactionAutoRuleCandidateQuery::MAX_CANDIDATES,
        ]);
    }
}
