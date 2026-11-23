<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\AutoRule\RequestCashRulePhraseAction;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_WRITE)]
#[Route('/cash-transaction-auto-rules/candidates/phrases', name: 'cash_transaction_auto_rule_phrase_request', methods: ['POST'])]
final class CashAutoRulePhraseRequestController extends AbstractController
{
    public function __invoke(Request $request, ActiveCompanyService $activeCompany, AuditContextProvider $actor, RequestCashRulePhraseAction $search): Response
    {
        $company = $activeCompany->getActiveCompany();
        if (!$this->isCsrfTokenValid('cash_rule_phrase_'.$company->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $parts = explode('/', $request->request->getString('target'));
        if (3 !== \count($parts) || !Uuid::isValid($parts[0]) || !\in_array($parts[1], ['INFLOW', 'OUTFLOW'], true) || !Uuid::isValid($parts[2])) {
            throw $this->createNotFoundException();
        }
        try {
            $status = $search((string) $company->getId(), (string) $actor->getActorUserId(), $parts[0], $parts[1], $parts[2]);
            $notice = match ($status) {
                'ready' => 'Фраза уже найдена. Проверьте её на платежах.',
                'insufficient' => 'По этим примерам устойчивой фразы не найдено.',
                'sending' => 'Запрос уже отправляется.',
                'pending' => 'Поиск в очереди. Результат появится в списке после обработки.',
                default => 'Не удалось отправить запрос. Поиск можно повторить.',
            };
            $this->addFlash(\in_array($status, ['sending', 'pending', 'ready'], true) ? 'success' : 'warning', $notice);
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('cash_transaction_auto_rule_candidates');
    }
}
