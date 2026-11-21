<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentIntake;

use App\Cash\Application\PaymentIntake\PaymentIntakeAction;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class McpCashDraftDecisionController extends AbstractController
{
    public function __construct(private readonly ActiveCompanyService $companies, private readonly PaymentIntakeAction $intake)
    {
    }

    #[Route('/finance/cash-transactions/mcp-drafts/{id}/{decision}', name: 'cash_mcp_draft_decide', requirements: ['id' => Requirement::UUID, 'decision' => 'confirm|cancel'], methods: ['POST'])]
    public function __invoke(Request $request, string $id, string $decision, #[CurrentUser] User $user): Response
    {
        $company = $this->companies->getActiveCompany();
        $companyId = (string) $company->getId();
        $userId = (string) $user->getId();
        if (!$this->isCsrfTokenValid('mcp_draft_'.$decision.'_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Некорректный токен формы.');
        }
        $paid = '1' === $request->request->getString('paid');
        if ('confirm' === $decision && !$paid) {
            return new Response('Подтвердите факт оплаты.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->intake->getMcpDraft($companyId, $userId, $id);
        } catch (\DomainException) {
            throw $this->createNotFoundException('Черновик не найден.');
        }
        $revision = $request->request->getInt('revision');
        try {
            if ('confirm' === $decision) {
                $this->intake->confirmMcp($companyId, $userId, $id, $revision, $paid);
            } else {
                $this->intake->cancelMcp($companyId, $userId, $id, $revision);
            }
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('cash_mcp_draft_show', ['id' => $id]);
    }
}
