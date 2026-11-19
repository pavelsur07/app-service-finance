<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Application\ProjectDirection\DeleteProjectDirectionAction;
use App\Company\Application\ProjectDirection\SaveProjectDirectionAction;
use App\Company\Entity\ProjectDirection;
use App\Company\Form\ProjectDirectionType;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/project-directions')]
class ProjectDirectionController extends AbstractController
{
    private const SORT_STEP = 10;

    #[Route('/', name: 'project_direction_index', methods: ['GET'])]
    public function index(ProjectDirectionRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findByCompany($company);

        return $this->render('project_direction/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/new', name: 'project_direction_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ActiveCompanyService $companyService, ProjectDirectionRepository $repo, SaveProjectDirectionAction $save): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $parents = $repo->findTreeByCompany($company);
        $nextSortByParent = $this->buildNextSortByParent(array_values($parents));
        $direction = (new ProjectDirection(Uuid::uuid7()->toString(), $company, ''))
            ->setSort($nextSortByParent['']);
        $form = $this->createForm(ProjectDirectionType::class, $direction, [
            'parents' => $parents,
        ]);
        $form->handleRequest($request);
        if ($this->saveForm($form, $direction, $save, true)) {
            return $this->redirectToRoute('project_direction_index');
        }

        return $this->render('project_direction/new.html.twig', [
            'form' => $form->createView(),
            'next_sort_by_parent' => $nextSortByParent,
        ]);
    }

    #[Route('/{id}/edit', name: 'project_direction_edit', requirements: ['id' => '(?i:'.Requirement::UID_RFC4122.')'], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, ActiveCompanyService $companyService, ProjectDirectionRepository $repo, SaveProjectDirectionAction $save): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $id = strtolower($id);
        $companyId = (string) $company->getId();
        // Сначала ревизия, чтобы форма с более ранними данными не получила новый номер.
        $revision = $request->isMethod('GET') ? $repo->getDirectoryRevision($companyId) : null;
        $direction = $repo->findOneByIdAndCompanyId($id, $companyId) ?? throw $this->createNotFoundException();
        $excludedIds = [$id => true];
        $parents = [];
        foreach ($repo->findTreeByCompany($company) as $candidate) {
            $parentId = $candidate->getParent()?->getId();
            $candidateId = (string) $candidate->getId();
            if (isset($excludedIds[$candidateId]) || (null !== $parentId && isset($excludedIds[$parentId]))) {
                $excludedIds[$candidateId] = true;
            } else {
                $parents[] = $candidate;
            }
        }
        $draft = clone $direction;
        $form = $this->createForm(ProjectDirectionType::class, $draft, [
            'parents' => $parents,
        ])->add('revision', HiddenType::class, ['mapped' => false, 'data' => $revision]);
        $form->handleRequest($request);
        if ($this->saveForm($form, $draft, $save, false)) {
            return $this->redirectToRoute('project_direction_index');
        }

        return $this->render('project_direction/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $direction,
        ]);
    }

    #[Route('/{id}/delete', name: 'project_direction_delete', requirements: ['id' => '(?i:'.Requirement::UID_RFC4122.')'], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(string $id, Request $request, ActiveCompanyService $companyService, ProjectDirectionRepository $repo, DeleteProjectDirectionAction $delete): Response
    {
        $companyId = (string) $companyService->getActiveCompany()->getId();
        $id = strtolower($id);
        if (null === $repo->findOneByIdAndCompanyId($id, $companyId)) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('delete'.$id, $request->request->getString('_token'))) {
            try {
                if (!$delete($companyId, $id)) {
                    throw $this->createNotFoundException();
                }
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('project_direction_index');
    }

    /** @param FormInterface<ProjectDirection> $form */
    private function saveForm(FormInterface $form, ProjectDirection $draft, SaveProjectDirectionAction $save, bool $create): bool
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return false;
        }

        $revision = $create ? null : $form->get('revision')->getData();
        $error = $save($draft, $create, \is_string($revision) && '' !== $revision ? $revision : null);
        if (null === $error) {
            return true;
        }
        $field = null === $error['field'] ? $form : $form->get($error['field']);
        $field->addError(new FormError($error['message']));

        return false;
    }

    /**
     * @param list<ProjectDirection> $directions
     *
     * @return array<string, int>
     */
    private function buildNextSortByParent(array $directions): array
    {
        $maxSortByParent = [];
        foreach ($directions as $direction) {
            $parentId = (string) $direction->getParent()?->getId();
            if (!isset($maxSortByParent[$parentId]) || $direction->getSort() > $maxSortByParent[$parentId]) {
                $maxSortByParent[$parentId] = $direction->getSort();
            }
        }

        $nextSortByParent = [
            '' => ($maxSortByParent[''] ?? 0) + self::SORT_STEP,
        ];
        foreach ($directions as $direction) {
            $id = (string) $direction->getId();
            $nextSortByParent[$id] = ($maxSortByParent[$id] ?? 0) + self::SORT_STEP;
        }

        return $nextSortByParent;
    }
}
