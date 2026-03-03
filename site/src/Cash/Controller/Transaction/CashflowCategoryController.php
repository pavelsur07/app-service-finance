<?php

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Form\Transaction\CashflowCategoryType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Repository\PLCategoryRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cashflow-categories')]
class CashflowCategoryController extends AbstractController
{
    #[Route('/', name: 'cashflow_category_index', methods: ['GET'])]
    public function index(
        CashflowCategoryRepository $repo,
        ActiveCompanyService $companyService,
    ): Response {
        $company = $companyService->getActiveCompany();
        $items = $repo->findRootByCompany($company);

        return $this->render('cashflow_category/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/export/json', name: 'cashflow_category_export_json', methods: ['GET'])]
    public function exportJson(
        CashflowCategoryRepository $repo,
        ActiveCompanyService $companyService,
    ): Response {
        $company = $companyService->getActiveCompany();
        $items = $repo->findRootByCompany($company);

        $payload = array_map(
            fn (CashflowCategory $category): array => $this->serializeCategory($category),
            $items,
        );

        $response = new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('cashflow-categories-%s.json', (new \DateTimeImmutable())->format('Y-m-d')),
            ),
        );

        return $response;
    }

    #[Route('/new', name: 'cashflow_category_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CashflowCategoryRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        PLCategoryRepository $plCategoryRepository,
    ): Response {
        $company = $companyService->getActiveCompany();
        $article = new CashflowCategory(Uuid::uuid4()->toString(), $company);

        $parents = $repo->findTreeByCompany($company);
        $plCategories = $plCategoryRepository->findTreeByCompany($company);

        $form = $this->createForm(CashflowCategoryType::class, $article, [
            'parents' => $parents,
            'plCategories' => $plCategories,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($article->getParent() && $article->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                $em->persist($article);
                $em->flush();

                return $this->redirectToRoute('cashflow_category_index');
            }
        }

        return $this->render('cashflow_category/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'cashflow_category_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CashflowCategory $article,
        CashflowCategoryRepository $repo,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
        PLCategoryRepository $plCategoryRepository,
    ): Response {
        $company = $companyService->getActiveCompany();
        if ($article->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $parents = $repo->findTreeByCompany($company);
        $plCategories = $plCategoryRepository->findTreeByCompany($company);

        $form = $this->createForm(CashflowCategoryType::class, $article, [
            'parents' => $parents,
            'plCategories' => $plCategories,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($article->getParent() && $article->getParent()->getLevel() >= 5) {
                $this->addFlash('danger', 'Максимальная вложенность — 5 уровней');
            } else {
                $em->flush();

                return $this->redirectToRoute('cashflow_category_index');
            }
        }

        return $this->render('cashflow_category/edit.html.twig', [
            'form' => $form->createView(),
            'article' => $article,
        ]);
    }

    #[Route('/{id}/delete', name: 'cashflow_category_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        CashflowCategory $article,
        EntityManagerInterface $em,
        ActiveCompanyService $companyService,
    ): Response {
        $company = $companyService->getActiveCompany();
        if ($article->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$article->getId(), $request->request->get('_token'))) {
            $em->remove($article);
            $em->flush();
        }

        return $this->redirectToRoute('cashflow_category_index');
    }

    private function serializeCategory(CashflowCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'status' => $category->getStatus()->value,
            'sort' => $category->getSort(),
            'parentId' => $category->getParent()?->getId(),
            'operationType' => $category->getOperationType()?->value,
            'allowPlDocument' => $category->isAllowPlDocument(),
            'plCategoryId' => $category->getPlCategory()?->getId(),
            'systemCode' => $category->getSystemCode(),
            'flowKind' => $category->getFlowKind()->value,
            'isSystem' => $category->isSystem(),
            'children' => array_map(
                fn (CashflowCategory $child): array => $this->serializeCategory($child),
                $category->getChildren()->toArray(),
            ),
        ];
    }
}
