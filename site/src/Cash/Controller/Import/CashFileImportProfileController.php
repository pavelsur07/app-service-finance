<?php

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cash/import/file/profiles')]
class CashFileImportProfileController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'cash_file_import_profile_index', methods: ['GET'])]
    public function index(CashFileImportProfileRepository $repository): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $profiles = $repository->findByCompanyAndType($company, CashFileImportProfile::TYPE_CASH_TRANSACTION);

        return $this->render('cash/file_import_profiles/index.html.twig', [
            'profiles' => $profiles,
        ]);
    }

    #[Route('/new', name: 'cash_file_import_profile_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $formData = [
            'name' => '',
            'mapping' => '',
            'options' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cash_file_import_profile_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Некорректный CSRF-токен.');

                return $this->redirectToRoute('cash_file_import_profile_new');
            }

            $formData = $this->extractFormData($request);
            $name = $formData['name'];
            if ('' === $name) {
                $this->addFlash('error', 'Укажите название профиля.');

                return $this->render('cash/file_import_profiles/new.html.twig', $formData);
            }

            $mapping = $this->decodeJson($formData['mapping'], 'маппинга');
            if (null === $mapping) {
                return $this->render('cash/file_import_profiles/new.html.twig', $formData);
            }

            $options = $this->decodeJson($formData['options'], 'опций');
            if (null === $options) {
                return $this->render('cash/file_import_profiles/new.html.twig', $formData);
            }

            $profile = new CashFileImportProfile(
                Uuid::uuid4()->toString(),
                $company,
                $name,
                $mapping,
                $options,
                CashFileImportProfile::TYPE_CASH_TRANSACTION
            );

            $this->entityManager->persist($profile);
            $this->entityManager->flush();

            return $this->redirectToRoute('cash_file_import_profile_index');
        }

        return $this->render('cash/file_import_profiles/new.html.twig', $formData);
    }

    #[Route('/{id}/edit', name: 'cash_file_import_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        string $id,
        CashFileImportProfileRepository $repository,
    ): Response {
        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $repository->find($id);

        if (!$profile || $profile->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        $formData = [
            'name' => $profile->getName(),
            'mapping' => json_encode($profile->getMapping(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'options' => json_encode($profile->getOptions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'profile' => $profile,
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cash_file_import_profile_form', $request->request->get('_token'))) {
                $this->addFlash('error', 'Некорректный CSRF-токен.');

                return $this->redirectToRoute('cash_file_import_profile_edit', ['id' => $profile->getId()]);
            }

            $formData = array_merge($formData, $this->extractFormData($request));
            $name = $formData['name'];
            if ('' === $name) {
                $this->addFlash('error', 'Укажите название профиля.');

                return $this->render('cash/file_import_profiles/edit.html.twig', $formData);
            }

            $mapping = $this->decodeJson($formData['mapping'], 'маппинга');
            if (null === $mapping) {
                return $this->render('cash/file_import_profiles/edit.html.twig', $formData);
            }

            $options = $this->decodeJson($formData['options'], 'опций');
            if (null === $options) {
                return $this->render('cash/file_import_profiles/edit.html.twig', $formData);
            }

            $profile->setName($name);
            $profile->setMapping($mapping);
            $profile->setOptions($options);
            $profile->setType(CashFileImportProfile::TYPE_CASH_TRANSACTION);

            $this->entityManager->flush();

            return $this->redirectToRoute('cash_file_import_profile_index');
        }

        return $this->render('cash/file_import_profiles/edit.html.twig', $formData);
    }

    #[Route('/{id}/delete', name: 'cash_file_import_profile_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        string $id,
        CashFileImportProfileRepository $repository,
    ): Response {
        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $repository->find($id);

        if (!$profile || $profile->getCompany()->getId() !== $company->getId()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$profile->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($profile);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('cash_file_import_profile_index');
    }

    /**
     * @return array{name: string, mapping: string, options: string}
     */
    private function extractFormData(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name')),
            'mapping' => (string) $request->request->get('mapping'),
            'options' => (string) $request->request->get('options'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $payload, string $label): ?array
    {
        if ('' === trim($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
            $this->addFlash('error', sprintf('Некорректный JSON для %s.', $label));

            return null;
        }

        return $decoded;
    }
}
