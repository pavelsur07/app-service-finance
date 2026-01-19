<?php

namespace App\Controller;

use App\Entity\ProjectDirection;
use App\Form\ProjectDirectionType;
use App\Repository\ProjectDirectionRepository;
use App\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/project-directions')]
class ProjectDirectionController extends AbstractController
{
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
        $parents = $repo->findTreeByCompany($company);
        $direction = new ProjectDirection(Uuid::uuid4()->toString(), $company, '');
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
        ]);
    }

    #[Route('/{id}/edit', name: 'project_direction_edit', methods: ['GET', 'POST'])]
    public function edit(ProjectDirection $direction, Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService, ProjectDirectionRepository $repo): Response
    {
        $company = $companyService->getActiveCompany();
        if ($direction->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        $parents = $repo->findTreeByCompany($company);
        $parentsFiltered = array_filter(
            $parents,
            static fn(ProjectDirection $parent) => $parent->getId() !== $direction->getId()
        );
        $form = $this->createForm(ProjectDirectionType::class, $direction, [
            'parents' => $parentsFiltered,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->redirectToRoute('project_direction_index');
        }

        return $this->render('project_direction/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $direction,
        ]);
    }

    #[Route('/{id}/delete', name: 'project_direction_delete', methods: ['POST'])]
    public function delete(ProjectDirection $direction, Request $request, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($direction->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('delete'.$direction->getId(), $request->request->get('_token'))) {
            $em->remove($direction);
            $em->flush();
        }

        return $this->redirectToRoute('project_direction_index');
    }
}
