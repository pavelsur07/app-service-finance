<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentPlan;

use App\Cash\Application\PaymentPlan\PostponePaymentPlanAction;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Company\Entity\Company;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/payment-calendar')]
final class PaymentCalendarPostponeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\PaymentCalendarPage $page,
        private readonly PostponePaymentPlanAction $postponePlan,
        private readonly PaymentPlanRepository $paymentPlanRepository,
    ) {
    }

    #[Route('/{id}/postpone', name: 'payment_calendar_postpone', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $plan = $this->findPlanOrNotFound($id, $company);
        if (!$this->isCsrfTokenValid('payment_plan_postpone_'.$plan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $filters = $this->page->extractFilters($request);
        $days = (int) $request->request->get('days', 0);
        if ($days <= 0) {
            $this->addFlash('danger', 'Количество дней должно быть больше нуля.');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        $plannedAt = $plan->getPlannedAt();
        if (null === $plannedAt) {
            $this->addFlash('danger', 'Не удалось перенести платёж.');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        ($this->postponePlan)($plan, $days);
        $this->addFlash('success', sprintf('Платёж перенесён на %d дн.', $days));

        return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
    }

    private function findPlanOrNotFound(string $id, Company $company): PaymentPlan
    {
        return $this->paymentPlanRepository->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
    }
}
