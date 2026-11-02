<?php

declare(strict_types=1);

namespace App\Billing\Controller\Admin;

use App\Billing\Application\CancelScheduledTariffAction;
use App\Billing\Exception\InvalidBillingInputException;
use App\Company\Facade\CompanyFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /admin/billing/companies/{companyId}/next-tariff/cancel — отменить запланированный переход.
 */
#[Route('/admin/billing/companies/{companyId}/next-tariff/cancel', name: 'admin_billing_company_cancel_next', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class CancelScheduledTariffController extends AbstractController
{
    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly CancelScheduledTariffAction $cancelScheduledTariff,
    ) {
    }

    public function __invoke(string $companyId, Request $request): RedirectResponse
    {
        $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        if (!$this->isCsrfTokenValid('billing_cancel_next_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        try {
            ($this->cancelScheduledTariff)(strtolower($companyId));
            $this->addFlash('success', 'Запланированный переход отменён.');
        } catch (InvalidBillingInputException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_billing_company', ['companyId' => $companyId]);
    }
}
