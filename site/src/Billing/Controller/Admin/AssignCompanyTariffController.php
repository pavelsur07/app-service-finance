<?php

declare(strict_types=1);

namespace App\Billing\Controller\Admin;

use App\Billing\Application\AssignTariffAction;
use App\Billing\Application\DTO\AssignTariffCommand;
use App\Billing\Exception\InvalidBillingInputException;
use App\Company\Facade\CompanyFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /admin/billing/companies/{companyId}/tariff — назначить тариф и период.
 */
#[Route('/admin/billing/companies/{companyId}/tariff', name: 'admin_billing_company_assign', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AssignCompanyTariffController extends AbstractController
{
    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly AssignTariffAction $assignTariff,
    ) {
    }

    public function __invoke(string $companyId, Request $request): RedirectResponse
    {
        $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        if (!$this->isCsrfTokenValid('billing_assign_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }

        try {
            ($this->assignTariff)(new AssignTariffCommand(
                $companyId,
                AdminBillingForm::tariff($request),
                AdminBillingForm::date($request, 'from', 'дату начала'),
                AdminBillingForm::optionalDate($request, 'until', 'дату окончания'),
            ));
            $this->addFlash('success', 'Тариф назначен.');
        } catch (InvalidBillingInputException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_billing_company', ['companyId' => $companyId]);
    }
}
