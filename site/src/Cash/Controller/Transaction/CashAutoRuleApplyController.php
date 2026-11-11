<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\ApplyAutoRuleToTransactionAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleApplyController extends AbstractController
{
    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ActiveCompanyService $companyService,
        private readonly ApplyAutoRuleToTransactionAction $applyAutoRule,
    ) {
    }

    #[Route('/apply/{transactionId}', name: 'cash_transaction_auto_rule_apply_one', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(string $transactionId, Request $request): Response
    {
        $csrfToken = new CsrfToken('apply-auto-rule'.$transactionId, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrfToken)) {
            return new JsonResponse(['ok' => false, 'changed' => false, 'reason' => 'invalid_csrf_token', 'message' => 'Недействительный CSRF-токен.'], Response::HTTP_FORBIDDEN);
        }
        $company = $this->companyService->getActiveCompany();
        $result = ($this->applyAutoRule)((string) $company->getId(), $transactionId, (string) $request->request->get('ruleId', ''));
        if (null === $result) {
            throw $this->createNotFoundException();
        }

        return new JsonResponse($result, 200);
    }
}
