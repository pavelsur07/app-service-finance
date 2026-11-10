<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Application\UpdateCashFileImportProfileAction;
use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file/profiles')]
final class CashFileImportProfileEditController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CashFileImportProfileRepository $repository,
        private readonly \App\Cash\Http\CashFileImportPage $page,
        private readonly UpdateCashFileImportProfileAction $updateProfile,
    ) {
    }

    #[Route('/{id}/edit', name: 'cash_file_import_profile_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $profile = $this->repository->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (null === $profile) {
            throw $this->createNotFoundException();
        }
        $formData = ['name' => $profile->getName(), 'mapping' => json_encode($profile->getMapping(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE), 'options' => json_encode($profile->getOptions(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE), 'profile' => $profile];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cash_file_import_profile_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Некорректный CSRF-токен.');

                return $this->redirectToRoute('cash_file_import_profile_edit', ['id' => $profile->getId()]);
            }
            $formData = array_merge($formData, $this->page->extractFormData($request));
            $name = $formData['name'];
            if ('' === $name) {
                $this->addFlash('error', 'Укажите название профиля.');

                return $this->render('cash/file_import_profiles/edit.html.twig', $formData, new Response(status: 422));
            }
            $mapping = $this->page->decodeJson($formData['mapping'], 'маппинга');
            if (null === $mapping) {
                return $this->render('cash/file_import_profiles/edit.html.twig', $formData, new Response(status: 422));
            }
            $options = $this->page->decodeJson($formData['options'], 'опций');
            if (null === $options) {
                return $this->render('cash/file_import_profiles/edit.html.twig', $formData, new Response(status: 422));
            }
            ($this->updateProfile)($profile, $name, $mapping, $options, CashFileImportProfile::TYPE_CASH_TRANSACTION);

            return $this->redirectToRoute('cash_file_import_profile_index');
        }

        return $this->render('cash/file_import_profiles/edit.html.twig', $formData);
    }
}
