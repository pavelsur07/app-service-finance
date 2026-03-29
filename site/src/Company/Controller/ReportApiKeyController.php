<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Service\ReportApiKeyManager;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/settings/report-api-key')]
class ReportApiKeyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ReportApiKeyManager $manager,
    ) {
    }

    #[Route('', name: 'settings_report_api_key_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/report_api_key.html.twig');
    }

    #[Route('/generate', name: 'settings_report_api_key_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings_report_api_key_generate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $company = $this->activeCompany->getActiveCompany();
        $raw = $this->manager->createOrRegenerateForCompany($company);

        $this->addFlash('success', 'Ключ создан. Сохраните: '.$raw);

        return $this->redirectToRoute('settings_report_api_key_index');
    }

    #[Route('/revoke', name: 'settings_report_api_key_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('settings_report_api_key_revoke', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $company = $this->activeCompany->getActiveCompany();
        $this->manager->revokeAll($company);

        $this->addFlash('success', 'Все ключи отозваны.');

        return $this->redirectToRoute('settings_report_api_key_index');
    }
}
