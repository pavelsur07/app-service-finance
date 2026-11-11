<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file')]
final class CashFileImportMappingProfileApplyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CashFileImportProfileRepository $profileRepository,
        private readonly \App\Cash\Http\CashImportSession $importSession,
        private readonly \App\Cash\Service\Import\File\CashFileMappingValidator $mappingValidator,
    ) {
    }

    #[Route('/mapping/profile/apply', name: 'cash_file_import_mapping_profile_apply', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $session = $request->getSession();
        if (!$this->isCsrfTokenValid('cash_file_import_profile_apply', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Некорректный CSRF-токен.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $profileId = $request->request->get('profile_id');
        if (!is_string($profileId) || '' === $profileId) {
            $this->addFlash('error', 'Выберите профиль импорта.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $this->profileRepository->findOneByIdAndCompanyId($profileId, (string) $company->getId());
        if (null === $profile || CashFileImportProfile::TYPE_CASH_TRANSACTION !== $profile->getType()) {
            $this->addFlash('error', 'Профиль импорта не найден.');

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        $importState = $this->importSession->read($session, $this->activeCompanyService->getActiveCompany());
        if (null === $importState) {
            $this->addFlash('error', 'Сессия импорта устарела или относится к другому счёту/компании. Загрузите файл заново.');

            return $this->redirectToRoute('cash_file_import_upload');
        }
        $importPayload = $importState->payload;
        try {
            $this->mappingValidator->validate($profile->getMapping());
        } catch (\App\Cash\Exception\InvalidCashFileMappingException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('cash_file_import_mapping');
        }
        unset($importPayload['description_plan'], $importPayload['description_context'], $importPayload['selected_descriptions'], $importPayload['description_filter'], $importPayload['committed_job_id']);
        $importPayload['mapping'] = $profile->getMapping();
        $importPayload['options'] = $profile->getOptions();
        $importPayload['profile_id'] = $profile->getId();
        $session->set('cash_file_import', $importPayload);
        $this->addFlash('success', 'Профиль применён.');

        return $this->redirectToRoute('cash_file_import_mapping');
    }
}
