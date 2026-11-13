<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentPlan;

use App\Cash\Application\PaymentPlan\ChangePaymentPlanStatusAction;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus as PaymentPlanStatusEnum;
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
final class PaymentCalendarStatusController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\PaymentCalendarPage $page,
        private readonly ChangePaymentPlanStatusAction $changePlanStatus,
        private readonly PaymentPlanRepository $paymentPlanRepository,
    ) {
    }

    #[Route('/{id}/status', name: 'payment_calendar_status', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $plan = $this->findPlanOrNotFound($id, $company);
        if (!$this->isCsrfTokenValid('payment_plan_status_'.$plan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $filters = $this->page->extractFilters($request);
        $action = (string) $request->request->get('action');
        $statusMap = ['approve' => PaymentPlanStatusEnum::APPROVED->value, 'pay' => PaymentPlanStatusEnum::PAID->value, 'cancel' => PaymentPlanStatusEnum::CANCELED->value];
        if (!\array_key_exists($action, $statusMap)) {
            $this->addFlash('danger', 'Неизвестное действие.');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        $targetStatus = $statusMap[$action];
        if ($plan->getStatus()->value === $targetStatus) {
            $this->addFlash('info', 'Статус уже установлен.');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        try {
            ($this->changePlanStatus)($plan, $targetStatus);
        } catch (\DomainException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        $messages = ['approve' => 'Платёж одобрен.', 'pay' => 'Платёж помечен как оплаченный.', 'cancel' => 'Платёж отменён.'];
        $this->addFlash('success', $messages[$action]);

        return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
    }

    private function findPlanOrNotFound(string $id, Company $company): PaymentPlan
    {
        return $this->paymentPlanRepository->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
    }
}
