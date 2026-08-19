<?php

declare(strict_types=1);

namespace App\Company\Controller;

use App\Company\Entity\ProjectDirection;
use App\Company\Form\ProjectDirectionType;
use App\Company\Repository\ProjectDirectionRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
    public function new(Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService, ProjectDirectionRepository $repo): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        $parents = $repo->findTreeByCompany($company);
        $nextSortByParent = $this->buildNextSortByParent($parents);
        $direction = (new ProjectDirection(Uuid::uuid4()->toString(), $company, ''))
            ->setSort($nextSortByParent['']);
        $form = $this->createForm(ProjectDirectionType::class, $direction, [
            'parents' => $parents,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($direction);
            $em->flush();

            return $this->redirectToRoute('project_direction_index');
        }

        return $this->render('project_direction/new.html.twig', [
            'form' => $form->createView(),
            'next_sort_by_parent' => $nextSortByParent,
        ]);
    }

    #[Route('/{id}/edit', name: 'project_direction_edit', methods: ['GET', 'POST'])]
    public function edit(ProjectDirection $direction, Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService, ProjectDirectionRepository $repo): Response
    {
        $company = $companyService->getActiveCompany();

        // Один экшен на GET и POST: read покрыт ModuleAccessSubscriber, write гейтим здесь.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(ModuleAccess::FINANCE_WRITE);
        }

        if ($direction->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $parents = $repo->findTreeByCompany($company);
        $excluded = $repo->collectSelfAndDescendants($direction);
        $excludedIdsMap = array_flip(array_map(static fn (ProjectDirection $direction) => (string) $direction->getId(), $excluded));
        $parentsFiltered = array_values(array_filter(
            $parents,
            static fn (ProjectDirection $parent) => !isset($excludedIdsMap[(string) $parent->getId()])
        ));
        $form = $this->createForm(ProjectDirectionType::class, $direction, [
            'parents' => $parentsFiltered,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $parent = $direction->getParent();
            if ($parent && isset($excludedIdsMap[(string) $parent->getId()])) {
                $this->addFlash('danger', 'Нельзя выбрать родителем текущий элемент или его потомка (цикл).');

                return $this->render('project_direction/edit.html.twig', [
                    'form' => $form->createView(),
                    'item' => $direction,
                ]);
            }
            $em->flush();

            return $this->redirectToRoute('project_direction_index');
        }

        return $this->render('project_direction/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $direction,
        ]);
    }

    #[Route('/{id}/delete', name: 'project_direction_delete', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function delete(ProjectDirection $direction, Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($direction->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('delete'.$direction->getId(), $request->request->get('_token'))) {
            if ($direction->isSystem()) {
                $this->addFlash('danger', 'Системный проект нельзя удалить.');

                return $this->redirectToRoute('project_direction_index');
            }

            $em->remove($direction);
            $em->flush();
        }

        return $this->redirectToRoute('project_direction_index');
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
