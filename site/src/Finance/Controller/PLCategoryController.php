<?php

namespace App\Finance\Controller;

use App\Company\Entity\User;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Entity\PLCategory;
use App\Finance\Form\PLCategoryFormType;
use App\Finance\Repository\PLCategoryRepository;
use App\Finance\Repository\PLDailyTotalRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
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

    #[Route('/import', name: 'pl_category_import', methods: ['GET'])]
    public function import(
        Request $request,
        ActiveCompanyService $companyService,
        CompanyFacade $companyFacade,
        ImportPLCategoryTreeAction $importAction,
    ): Response {
        $targetCompany = $companyService->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $sources = array_values(array_filter(
            $companyFacade->listAccessibleCompaniesForUser((string) $user->getId()),
            static fn (array $c): bool => $c['id'] !== (string) $targetCompany->getId(),
        ));

        $sourceCompanyId = $request->query->get('sourceCompanyId');
        $preview = null;

        if (is_string($sourceCompanyId) && '' !== $sourceCompanyId) {
            if (!$companyFacade->userHasAccess($sourceCompanyId, (string) $user->getId())) {
                throw new AccessDeniedException();
            }

            try {
                $preview = $importAction(new ImportPLCategoryTreeCommand(
                    $sourceCompanyId,
                    (string) $targetCompany->getId(),
                    true,
                ));
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('pl_category/import.html.twig', [
            'sources' => $sources,
            'selectedSourceCompanyId' => $sourceCompanyId,
            'targetCompanyId' => (string) $targetCompany->getId(),
            'preview' => $preview,
        ]);
    }

    #[Route('/import/apply', name: 'pl_category_import_apply', methods: ['POST'])]
    public function importApply(
        Request $request,
        ActiveCompanyService $companyService,
        CompanyFacade $companyFacade,
        ImportPLCategoryTreeAction $importAction,
    ): Response {
        $targetCompany = $companyService->getActiveCompany();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $sourceCompanyId = $request->request->get('sourceCompanyId');
        if (!is_string($sourceCompanyId) || '' === $sourceCompanyId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('pl-category-import'.(string) $targetCompany->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$companyFacade->userHasAccess($sourceCompanyId, (string) $user->getId())) {
            throw new AccessDeniedException();
        }

        try {
            $result = $importAction(new ImportPLCategoryTreeCommand(
                $sourceCompanyId,
                (string) $targetCompany->getId(),
                false,
            ));
        } catch (\DomainException $e) {
            // Без sourceCompanyId в redirect: GET заново пересчитал бы тот же
            // dry-run и добавил бы второй, дублирующий flash с той же ошибкой.
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('pl_category_import');
        }

        $this->addFlash('success', sprintf(
            'Импорт завершён: создано %d, обновлено %d.',
            \count($result->created),
            \count($result->updated),
        ));

        return $this->redirectToRoute('pl_category_index');
    }

    #[Route('/new', name: 'pl_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, PLCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        $category = new PLCategory(Uuid::uuid4()->toString(), $company);

        $availableCategories = $repo->findTreeByCompany($company);
        $nextSortOrder = $repo->getNextSortOrder($company, $category->getParent());
        if (null === $category->getSortOrder()) {
            $category->setSortOrder($nextSortOrder);
        }

        $form = $this->createForm(PLCategoryFormType::class, $category, [
            'parents' => $availableCategories,
            'expanded_choices' => true,
        ]);
        $form->remove('isVisible');
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
            'available_categories' => $availableCategories,
            'is_new' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'pl_category_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PLCategory $category, PLCategoryRepository $repo, EntityManagerInterface $em, ActiveCompanyService $companyService): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        $availableCategories = $repo->findTreeByCompany($company);
        $parents = array_values(array_filter(
            $availableCategories,
            static fn (PLCategory $candidate): bool => $candidate->getId() !== $category->getId()
                && !$candidate->isDescendantOf($category),
        ));
        $form = $this->createForm(PLCategoryFormType::class, $category, [
            'parents' => $parents,
            'expanded_choices' => true,
        ]);
        $form->remove('isVisible');
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
            'available_categories' => $availableCategories,
        ]);
    }

    #[Route('/{id}/delete', name: 'pl_category_delete', methods: ['POST'])]
    public function delete(Request $request, PLCategory $category, EntityManagerInterface $em, ActiveCompanyService $companyService, PLDailyTotalRepository $dailyTotalRepository): Response
    {
        $company = $companyService->getActiveCompany();
        if ($category->getCompany() !== $company) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('delete'.$category->getId(), $request->request->get('_token'))) {
            $companyId = $company->getId();
            $categoryId = $category->getId();
            $em->wrapInTransaction(static function () use ($em, $dailyTotalRepository, $companyId, $categoryId, $category): void {
                if (null !== $companyId && null !== $categoryId) {
                    $dailyTotalRepository->moveCategoryRowsToUncategorized($companyId, $categoryId);
                }

                $em->remove($category);
            });
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
            'expenseType' => $category->getExpenseType()->value,
            'weightInParent' => $category->getWeightInParent(),
            'isVisible' => $category->isVisible(),
            'formula' => $category->getFormula(),
            'calcOrder' => $category->getCalcOrder(),
            'children' => $children,
        ];
    }
}
