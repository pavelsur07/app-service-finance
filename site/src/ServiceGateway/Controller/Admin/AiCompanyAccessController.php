<?php

declare(strict_types=1);

namespace App\ServiceGateway\Controller\Admin;

use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\ServiceGateway\Application\Action\SetAiCompanyAccessAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * POST /admin/ai/companies/{companyId}/access — подключить кабинет к ИИ (`enabled=1`) или отключить.
 * Возврат — на список пользователей с теми же фильтрами (`return_query`).
 */
#[Route('/admin/ai/companies/{companyId}/access', name: 'admin_ai_company_access', requirements: ['companyId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class AiCompanyAccessController extends AbstractController
{
    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly SetAiCompanyAccessAction $setAccess,
    ) {
    }

    public function __invoke(string $companyId, Request $request): RedirectResponse
    {
        $company = $this->companies->findById($companyId) ?? throw $this->createNotFoundException('Кабинет не найден.');
        if (!$this->isCsrfTokenValid('ai_company_access_'.$companyId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Неверный CSRF-токен.');
        }
        $admin = $this->getUser();
        \assert($admin instanceof User);

        $enabled = $request->request->getBoolean('enabled');
        ($this->setAccess)($companyId, $enabled, (string) $admin->getId());
        $this->addFlash('success', sprintf('ИИ %s: %s.', $enabled ? 'подключён' : 'отключён', $company->getName()));

        parse_str($request->request->getString('return_query'), $query);

        return $this->redirectToRoute('admin_user_index', array_intersect_key($query, array_flip(['page', 'company', 'owners'])));
    }
}
