<?php

declare(strict_types=1);

namespace App\Cash\Controller\PaymentPlan;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/payment-calendar')]
final class PaymentCalendarIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\PaymentCalendarPage $page,
    ) {
    }

    #[Route('', name: 'payment_calendar_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->page->renderCalendar($request, $company, []);
    }
}
