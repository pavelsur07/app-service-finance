<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashAutoRuleQualityPeriod;
use App\Cash\Enum\Transaction\CashAutoRuleQualityMetric;
use App\Cash\Infrastructure\Query\CashAutoRuleQualityQuery;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CashAutoRuleQualityController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companies,
        private readonly CashTransactionAutoRuleRepository $rules,
        private readonly CashAutoRuleQualityQuery $quality,
    ) {
    }

    #[Route('/cash-transaction-auto-rules/{id}/quality/{revision}/{metric}', name: 'cash_transaction_auto_rule_quality', requirements: ['id' => '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', 'revision' => '[1-9]\d*', 'metric' => 'assigned|corrected|conflicts'], methods: ['GET'])]
    public function __invoke(Request $request, string $id, int $revision, CashAutoRuleQualityMetric $metric): Response
    {
        $companyId = (string) $this->companies->getActiveCompany()->getId();
        $rule = $this->rules->findOneByIdAndCompanyId($id, $companyId) ?? throw $this->createNotFoundException();
        if ($revision > $rule->getRevision()) {
            throw $this->createNotFoundException();
        }
        $period = CashAutoRuleQualityPeriod::fromStrings($request->query->getString('from'), $request->query->getString('to'));

        return $this->render('cash_transaction_auto_rule/quality.html.twig', [
            'rule' => $rule, 'revision' => $revision, 'metric' => $metric, 'period' => $period,
            'observedSince' => CashAutoRuleQualityMetric::CONFLICTS === $metric ? $this->quality->observedSince($companyId) : null,
            'pager' => $this->quality->operations($companyId, $id, $revision, $metric, $period, max(1, $request->query->getInt('page', 1))),
        ]);
    }
}
