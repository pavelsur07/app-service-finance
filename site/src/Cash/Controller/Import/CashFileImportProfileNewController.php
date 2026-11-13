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
#[Route('/cash/import/file/profiles')]
final class CashFileImportProfileNewController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly \App\Cash\Http\CashFileImportPage $page,
        private readonly CreateCashFileImportProfileAction $createProfile,
    ) {
    }

    #[Route('/new', name: 'cash_file_import_profile_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }
        $formData = ['name' => '', 'mapping' => '', 'options' => ''];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cash_file_import_profile_form', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Некорректный CSRF-токен.');

                return $this->redirectToRoute('cash_file_import_profile_new');
            }
            $formData = $this->page->extractFormData($request);
            $name = $formData['name'];
            if ('' === $name) {
                $this->addFlash('error', 'Укажите название профиля.');

                return $this->render('cash/file_import_profiles/new.html.twig', $formData, new Response(status: 422));
            }
            $mapping = $this->page->decodeJson($formData['mapping'], 'маппинга', validateMapping: true);
            if (null === $mapping) {
                return $this->render('cash/file_import_profiles/new.html.twig', $formData, new Response(status: 422));
            }
            $options = $this->page->decodeJson($formData['options'], 'опций');
            if (null === $options) {
                return $this->render('cash/file_import_profiles/new.html.twig', $formData, new Response(status: 422));
            }
            ($this->createProfile)($company, $name, $mapping, $options, CashFileImportProfile::TYPE_CASH_TRANSACTION);

            return $this->redirectToRoute('cash_file_import_profile_index');
        }

        return $this->render('cash/file_import_profiles/new.html.twig', $formData);
    }
}
