<?php

namespace App\Controller;

use App\Entity\PLCategory;
use App\Form\PLCategoryType;
use App\Repository\PLCategoryRepository;
use App\Service\CompanyContextService;
use App\Service\PLCategoryTree;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Nonstandard\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pl/categories')]
class PLCategoryController extends AbstractController
{
    #[Route('', name: 'pl_category_index', methods: ['GET'])]
    public function index(PLCategoryRepository $repository, CompanyContextService $context): Response
    {
        $company = $context->getCompany();
        $items = $repository->qbForCompany($company)->getQuery()->getResult();
        $tree = PLCategoryTree::build($items);

        return $this->render('pl_category/index.html.twig', [
            'tree' => $tree,
        ]);
    }

    #[Route('/new', name: 'pl_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, CompanyContextService $context, PLCategoryRepository $repository): Response
    {
        $company = $context->getCompany();
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);

        if ($parentId = $request->query->get('parent')) {
            $parent = $repository->find($parentId);
            if ($parent && $parent->getCompany()->getId() === $company->getId()) {
                $category->setParent($parent);
                $category->setSortOrder($parent->getChildren()->count() + 1);
            }
        }

        $form = $this->createForm(PLCategoryType::class, $category, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($category);
            $entityManager->flush();

            $this->addFlash('success', 'Категория создана');

            return $this->redirectToRoute('pl_category_index');
        }

        return $this->render('pl_category/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'pl_category_edit', methods: ['GET', 'POST'])]
    public function edit(PLCategory $category, Request $request, EntityManagerInterface $entityManager, CompanyContextService $context): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $company = $context->getCompany();
        if ($category->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(PLCategoryType::class, $category, ['company' => $company]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Сохранено');

            return $this->redirectToRoute('pl_category_index');
        }

        return $this->render('pl_category/edit.html.twig', [
            'form' => $form->createView(),
            'category' => $category,
        ]);
    }

    #[Route('/{id}/delete', name: 'pl_category_delete', methods: ['POST'])]
    public function delete(PLCategory $category, Request $request, EntityManagerInterface $entityManager, CompanyContextService $context): Response
    {
        if (!$this->isCsrfTokenValid('del' . $category->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $context->getCompany();
        if ($category->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($category->getChildren()->count() > 0) {
            $this->addFlash('warning', 'Удаление запрещено: есть дочерние категории.');

            return $this->redirectToRoute('pl_category_index');
        }

        $entityManager->remove($category);
        $entityManager->flush();

        $this->addFlash('success', 'Удалено');

        return $this->redirectToRoute('pl_category_index');
    }

    #[Route('/{id}/move-up', name: 'pl_category_move_up', methods: ['POST'])]
    public function moveUp(PLCategory $category, Request $request, EntityManagerInterface $entityManager, CompanyContextService $context, PLCategoryRepository $repository): Response
    {
        if (!$this->isCsrfTokenValid('up' . $category->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $context->getCompany();
        if ($category->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        $siblings = $repository->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent = :parent')
            ->setParameter('company', $company)
            ->setParameter('parent', $category->getParent())
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $count = count($siblings);
        for ($index = 0; $index < $count; $index++) {
            if ($siblings[$index]->getId() === $category->getId() && $index > 0) {
                $previous = $siblings[$index - 1];
                $currentOrder = $category->getSortOrder();
                $category->setSortOrder($previous->getSortOrder());
                $previous->setSortOrder($currentOrder);
                $entityManager->flush();
                break;
            }
        }

        return $this->redirectToRoute('pl_category_index');
    }

    #[Route('/{id}/move-down', name: 'pl_category_move_down', methods: ['POST'])]
    public function moveDown(PLCategory $category, Request $request, EntityManagerInterface $entityManager, CompanyContextService $context, PLCategoryRepository $repository): Response
    {
        if (!$this->isCsrfTokenValid('down' . $category->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $context->getCompany();
        if ($category->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        $siblings = $repository->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent = :parent')
            ->setParameter('company', $company)
            ->setParameter('parent', $category->getParent())
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        $count = count($siblings);
        for ($index = 0; $index < $count; $index++) {
            if ($siblings[$index]->getId() === $category->getId() && $index < $count - 1) {
                $next = $siblings[$index + 1];
                $currentOrder = $category->getSortOrder();
                $category->setSortOrder($next->getSortOrder());
                $next->setSortOrder($currentOrder);
                $entityManager->flush();
                break;
            }
        }

        return $this->redirectToRoute('pl_category_index');
    }

    #[Route('/{id}/toggle-visible', name: 'pl_category_toggle_visible', methods: ['POST'])]
    public function toggleVisible(PLCategory $category, Request $request, EntityManagerInterface $entityManager, CompanyContextService $context): Response
    {
        if (!$this->isCsrfTokenValid('vis' . $category->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $company = $context->getCompany();
        if ($category->getCompany()->getId() !== $company->getId()) {
            throw $this->createAccessDeniedException();
        }

        $category->setIsVisible(!$category->isVisible());
        $entityManager->flush();

        return $this->redirectToRoute('pl_category_index');
    }
}
