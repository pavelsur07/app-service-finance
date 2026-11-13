<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Application\Import\File\CreateCashFileImportProfileAction;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportMappingProfileSaveController extends AbstractController
{
    public function __construct(
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\CashFileImportPage $page,
        private readonly CreateCashFileImportProfileAction $createProfile,
    ) {
    }

    #[Route('/mapping/profile/save', name: 'cash_file_import_mapping_profile_save', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isCsrfTokenValid('cash_file_import_mapping', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany());
        if (null === $importState) {
            $this->addFlash('error', 'Сессия импорта устарела или относится к другому счёту/компании. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $importPayload = $importState->payload;
        $profileName = trim((string) $request->request->get('profile_name'));
        if ('' === $profileName) {
            $this->addFlash('error', 'Укажите название профиля.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $mapping = $this->page->buildMappingFromRequest($request);
        if (null === $mapping) {
            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $options = [];
        if (isset($importPayload['options']) && is_array($importPayload['options'])) {
            $options = $importPayload['options'];
        }
        $company = $this->activeCompanyService->getActiveCompany();
        $profile = ($this->createProfile)($company, $profileName, $mapping, $options, CashFileImportProfile::TYPE_CASH_TRANSACTION);
        $importPayload['mapping'] = $mapping;
        unset($importPayload['description_plan'], $importPayload['description_context'], $importPayload['selected_descriptions'], $importPayload['description_filter'], $importPayload['committed_job_id']);
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);
        $this->addFlash('success', 'Профиль сохранён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }
}
