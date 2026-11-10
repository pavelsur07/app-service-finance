<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentPlan;

use App\Cash\Application\CreatePaymentPlanAction;
use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Form\PaymentPlan\PaymentPlanType;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/payment-calendar')]
final class PaymentCalendarNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\PaymentCalendarPage $page,
        private readonly CreatePaymentPlanAction $createPlan,
    ) {
    }

    #[Route('/new', name: 'payment_calendar_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $filters = $this->page->extractFilters($request);
        $dto = new PaymentPlanDTO();
        $dto->idempotencyKey = Uuid::uuid7()->toString();
        $form = $this->createForm(PaymentPlanType::class, $dto, ['company' => $company, 'action' => $this->generateUrl('payment_calendar_new', $this->page->buildFilterQuery($filters)), 'idempotency_key' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && null !== $dto->cashflowCategory) {
            ($this->createPlan)($company, $dto);
            $this->addFlash('success', 'Создано');

            return $this->redirectToRoute('payment_calendar_index', $this->page->buildFilterQuery($filters));
        }

        return $this->page->renderCalendar($request, $company, ['form' => $form->createView(), 'showForm' => true, 'formTitle' => 'Новая запись'], $filters);
    }
}
