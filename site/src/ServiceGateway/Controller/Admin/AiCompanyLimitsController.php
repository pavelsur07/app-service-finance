<?php

declare(strict_types=1);

namespace App\ServiceGateway\Controller\Admin;

use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\ServiceGateway\Application\Action\UpdateAiCompanyLimitsAction;
use App\ServiceGateway\Exception\InvalidAiCompanyLimitsException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /admin/ai/companies/{companyId}/limits — лимит расходов ИИ и глубина выгрузки кабинета.
 */
#[Route('/admin/ai/companies/{companyId}/limits', name: 'admin_ai_company_limits', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AiCompanyLimitsController extends AbstractController
{
    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly UpdateAiCompanyLimitsAction $updateLimits,
    ) {
    }

    public function __invoke(string $companyId, Request $request): RedirectResponse
    {
        $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        if (!$this->isCsrfTokenValid('ai_company_limits_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $admin = $this->getUser();
        \assert($admin instanceof User);

        try {
            ($this->updateLimits)($companyId, $request->request->getString('monthly_limit_rub'), $request->request->getInt('lookback_days'), (string) $admin->getId());
            $this->addFlash('success', 'Лимиты ИИ сохранены.');
        } catch (InvalidAiCompanyLimitsException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_ai_company', ['companyId' => $companyId]);
    }
}
