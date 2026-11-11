<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentPlan;

use App\Cash\Application\UpdatePaymentPlanAction;
use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Form\PaymentPlan\PaymentPlanType;
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
final class PaymentCalendarEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\PaymentCalendarPage $page,
        private readonly UpdatePaymentPlanAction $updatePlan,
        private readonly PaymentPlanRepository $paymentPlanRepository,
    ) {
    }

    #[Route('/{id}/edit', name: 'payment_calendar_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $plan = $this->findPlanOrNotFound($id, $company);
        $filters = $this->page->extractFilters($request);
        if (null === $plan->getPlannedAt()) {
            $this->addFlash('danger', 'У платежа не указана дата. Редактирование недоступно.');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }
        $dto = PaymentPlanDTO::fromPlan($plan);
        $form = $this->createForm(PaymentPlanType::class, $dto, ['company' => $company, 'action' => $this->generateUrl('payment_calendar_edit', array_merge(['id' => (string) $plan->getId()], $this->page->buildFilterQuery($filters)))]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && null !== $dto->cashflowCategory) {
            ($this->updatePlan)($plan, $company, $dto);
            $this->addFlash('success', 'Сохранено');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }

        return $this->page->renderCalendar($request, $company, ['form' => $form->createView(), 'showForm' => true, 'formTitle' => 'Редактирование'], $filters);
    }

    private function findPlanOrNotFound(string $id, Company $company): PaymentPlan
    {
        return $this->paymentPlanRepository->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
    }
}
