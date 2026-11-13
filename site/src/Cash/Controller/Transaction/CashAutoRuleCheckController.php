<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionAutoRulePreviewFilter;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleService;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleCheckController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionAutoRuleRepository $ruleRepo,
        private readonly CashTransactionAutoRuleService $autoRuleService,
        private readonly CashTransactionRepository $txRepo,
    ) {
    }

    #[Route('/{id}/check', name: 'cash_transaction_auto_rule_check', methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $rule = $this->ruleRepo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }
        $today = new \DateTimeImmutable('today');
        $dateFrom = (string) $request->query->get('dateFrom', $today->modify('-6 months')->format('Y-m-d'));
        $dateTo = (string) $request->query->get('dateTo', $today->format('Y-m-d'));
        $limit = (string) $request->query->get('limit', '200');
        try {
            $filter = CashTransactionAutoRulePreviewFilter::fromStrings($dateFrom, $dateTo, $limit);
        } catch (\InvalidArgumentException $exception) {
            return $this->render('cash_transaction_auto_rule/check.html.twig', ['rule' => $rule, 'previewRows' => [], 'limit' => 200, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'filterError' => $exception->getMessage(), 'preview' => null], new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $preview = $this->autoRuleService->previewRule($rule, $this->txRepo->iterateForAutoRulePreview($company, $filter->dateFrom, $filter->dateTo), $filter->limit);

        return $this->render('cash_transaction_auto_rule/check.html.twig', ['rule' => $rule, 'previewRows' => $preview->rows, 'preview' => $preview, 'limit' => $filter->limit, 'dateFrom' => $filter->dateFrom->format('Y-m-d'), 'dateTo' => $filter->dateTo->format('Y-m-d'), 'filterError' => null]);
    }
}
