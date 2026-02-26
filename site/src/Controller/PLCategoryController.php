<?php

namespace App\Controller;

use App\Entity\PLCategory;
use App\Form\PLCategoryFormType;
use App\Repository\PLCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pl-categories')]
class PLCategoryController extends AbstractController
{
    #[Route('/', name: 'pl_category_index', methods: ['GET'])]
    public function index(PLCategoryRepository $repo, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findRootByCompany($company);

        return $this->render('pl_category/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/export/json', name: 'pl_category_export_json', methods: ['GET'])]
    public function exportJson(PLCategoryRepository $repo, ActiveCompanyService $companyService): JsonResponse
    {
        $company = $companyService->getActiveCompany();
        $items = $repo->findRootByCompany($company);

        $data = array_map(function (PLCategory $category): array {
            return $this->serializeCategory($category);
        }, $items);

        return $this->json($data, Response::HTTP_OK, [], [
            'json_encode_options' => \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        ]);
    }

    #[Route('/new', name: 'pl_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, PLCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);

        $parents = $repo->findTreeByCompany($company);
        $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
        if (null === $category->getSortOrder()) {
            $category->setSortOrder($nextSortOrder);
        }

        $form = $this->createForm(PLCategoryFormType::class, $category, ['parents' => $parents]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($category->getParent() && $category->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
                $category->setSortOrder($nextSortOrder);
                $em->persist($category);
                $em->flush();

                return $this->redirectToRoute('pl_category_index');
            }
        }

        return $this->render('pl_category/new.html.twig', [
            'form' => $form->createView(),
            'available_codes' => $repo->findCodesByCompany($company),
        ]);
    }

    #[Route('/{id}/edit', name: 'pl_category_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PLCategory $category, PLCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $parents = $repo->findTreeByCompany($company);
        $form = $this->createForm(PLCategoryFormType::class, $category, ['parents' => $parents]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($category->getParent() && $category->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                $em->flush();

                return $this->redirectToRoute('pl_category_index');
            }
        }

        return $this->render('pl_category/edit.html.twig', [
            'form' => $form->createView(),
            'item' => $category,
            'available_codes' => $repo->findCodesByCompany($company),
        ]);
    }

    #[Route('/{id}/delete', name: 'pl_category_delete', methods: ['POST'])]
    public function delete(Request $request, PLCategory $category, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $em->remove($category);
            $em->flush();
        }

        return $this->redirectToRoute('pl_category_index');
    }

    private function serializeCategory(PLCategory $category): array
    {
        $children = [];
        foreach ($category->getChildren() as $child) {
            $children[] = $this->serializeCategory($child);
        }

        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'code' => $category->getCode(),
            'level' => $category->getLevel(),
            'sortOrder' => $category->getSortOrder(),
            'type' => $category->getType()->value,
            'format' => $category->getFormat()->value,
            'flow' => $category->getFlow()->value,
            'weightInParent' => $category->getWeightInParent(),
            'isVisible' => $category->isVisible(),
            'formula' => $category->getFormula(),
            'calcOrder' => $category->getCalcOrder(),
            'children' => $children,
        ];
    }
}
